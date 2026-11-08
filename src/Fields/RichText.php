<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Files\FileTypes;
use Fastleo\Support\Html\DepthLimitedParser;
use Fastleo\Support\HtmlSanitizer;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Текст с оформлением в визуальном редакторе (HugeRTE): заголовки, списки, ссылки,
 * картинки из файлового менеджера, таблицы, видео. HTML чистится при сохранении —
 * на сайте его можно выводить `{!! $article->content !!}`.
 *
 * ```php
 * RichText::make('content', 'Текст')->height(500);
 * ```
 */
class RichText extends Field
{
    /** Длинный текст в списке не показывается столбцом; `showInList()` вернёт начало текста. */
    protected bool $showInList = false;

    /** Высота редактора в пикселях. */
    protected int $height = 400;

    /** Дополнение профиля чистки или null. */
    protected ?Closure $sanitizeUsing = null;

    /**
     * Высота редактора в пикселях (по умолчанию 400).
     *
     * ```php
     * RichText::make('content')->height(600);
     * ```
     */
    public function height(int $pixels): static
    {
        $this->height = max(150, $pixels);

        return $this;
    }

    /**
     * Дополнить профиль чистки HTML (по умолчанию — структура текста, см. docs/fields/rich-text.md).
     *
     * ```php
     * RichText::make('content')->sanitizeUsing(fn (HtmlSanitizerConfig $config) => $config->allowElement('span', ['class']));
     * ```
     *
     * @param  Closure(HtmlSanitizerConfig): HtmlSanitizerConfig  $customize
     */
    public function sanitizeUsing(Closure $customize): static
    {
        $this->sanitizeUsing = $customize;

        return $this;
    }

    /**
     * Высота редактора.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Очистить HTML по профилю поля.
     */
    public function sanitize(?string $html): ?string
    {
        return HtmlSanitizer::clean($html, $this->sanitizeUsing);
    }

    /**
     * Адрес окна выбора картинки для редактора.
     */
    public function pickerUrl(): string
    {
        return route('fastleo.files.index', ['picker' => 'field', 'accept' => 'images']);
    }

    /**
     * Расширения картинок, которые можно вставить.
     *
     * @return list<string>
     */
    public function imageExtensions(): array
    {
        return FileTypes::images();
    }

    /**
     * HTML из формы → очищенный HTML (пустой текст → null); у многоязычного — по каждому языку.
     *
     * @return string|array<string, mixed>|null
     */
    #[Override]
    public function dehydrate(mixed $value): string|array|null
    {
        if ($this->translatable) {
            return $this->dehydrateTranslations($value, fn (mixed $html): ?string => is_string($html) ? $this->sanitize($html) : null);
        }

        return is_string($value) ? $this->sanitize($value) : null;
    }

    /**
     * `required`; у записи, у которой сохранён текст, слишком сложный для редактора, поле можно не присылать: форма его
     * не отправляет (`resources/views/fields/rich-text.blade.php`), и обязательное поле иначе нельзя было бы сохранить.
     * Присланное пустое значение при этом по-прежнему отклоняется: `sometimes` проверяет поле, только если оно прислано.
     *
     * @return string|list<string>
     */
    #[Override]
    protected function presenceRule(?Model $record): string|array
    {
        if (! $this->required) {
            return 'nullable';
        }

        $stored = $record?->getAttribute($this->name);

        return is_string($stored) && HtmlSanitizer::tooComplex($stored) ? ['sometimes', 'required'] : 'required';
    }

    #[Override]
    protected function typeRules(): array
    {
        return [
            // Дальше текст разбирается (дважды-трижды за запрос), поэтому слишком длинный и слишком сложный отсекается первым.
            'bail',
            'string',
            'max:'.HtmlSanitizer::MAX_LENGTH,
            // Тысячи вложенных тегов роняют PHP-процесс в санитайзере, а тысячи лишних тегов занимают воркер: такой текст отклоняется до очистки.
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && HtmlSanitizer::tooComplex($value)) {
                    $fail(__('fastleo::resources.rich_text_too_complex', ['depth' => DepthLimitedParser::MAX_DEPTH, 'tags' => number_format(DepthLimitedParser::MAX_TAGS, 0, ',', ' '), 'attributes' => number_format(DepthLimitedParser::MAX_ATTRIBUTES, 0, ',', ' ')], Fastleo::LOCALE));
                }
            },
            // Текст из одних пробелов и пустых тегов (`<p>&nbsp;</p>`) после очистки пуст: обязательное поле остаётся незаполненным.
            // У многоязычного обязателен только основной язык: в остальных языках пустой текст допустим.
            ...($this->isRequired() ? [function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->translatable && ! str_ends_with($attribute, '.'.Fastleo::locales()[0])) {
                    return;
                }

                if (is_string($value) && trim($value) !== '' && $this->sanitize($value) === null) {
                    $fail(ValidationMessages::line('required'));
                }
            }] : []),
        ];
    }

    /**
     * На просмотре — оформленный текст (чистится ещё раз: в базе мог оказаться HTML не из админки).
     */
    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        $html = is_string($value) ? $this->sanitize($value) : null;

        return $html === null ? null : new HtmlString('<div class="fastleo-prose">'.$html.'</div>');
    }

    /**
     * В списке — начало текста без тегов.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof Closure) {
            return $this->display($record);
        }

        $value = $record->getAttribute($this->name);
        // Между абзацами, заголовками и пунктами — пробел, иначе слова склеятся.
        $spaced = is_string($value) ? (string) preg_replace('~<(/(p|h[1-6]|li|div|td|th|blockquote|figcaption|pre)|br)\b~i', ' $0', $value) : '';
        $text = trim(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : Str::limit((string) preg_replace('/\s+/u', ' ', $text), 100);
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return true;
    }
}
