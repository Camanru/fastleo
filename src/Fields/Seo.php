<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\StoresJsonItems;
use Fastleo\Support\FormSchema;
use Fastleo\Support\JsonItem;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Поисковая выдача и соцсети для записи: заголовок, описание, картинка и запрет индексации в одной JSON-колонке.
 *
 * Колонка `json`, в модели — каст `'array'` (без него — понятная ошибка). Хранится объект
 * `{"title": "…", "description": "…", "image": "uploads/seo/a.jpg", "noindex": true}`; пустые значения не сохраняются.
 * В форме — счётчики длины и превью сниппета поисковика. На сайте значения выводит `<x-fastleo::seo>`.
 *
 * ```php
 * Seo::make('seo');                       // подпись «SEO»
 * Seo::make('seo', 'Для поисковиков')->directory('articles');
 * ```
 */
class Seo extends Field
{
    use StoresJsonItems;

    /** Не показывается в таблице списка. */
    protected bool $showInList = false;

    /** Не выгружается в Excel/CSV. */
    protected bool $exportable = false;

    /** Папка картинки внутри диска загрузок. */
    protected string $directory = 'seo';

    /** Схема подполей. */
    private ?FormSchema $schema = null;

    /**
     * Поле с подписью «SEO», если другая не задана.
     */
    #[Override]
    public static function make(string $name = 'seo', ?string $label = null): static
    {
        return new static($name, $label ?? 'SEO');
    }

    /**
     * Папка для картинки соцсетей (по умолчанию `seo`).
     *
     * ```php
     * Seo::make('seo')->directory('articles/seo');
     * ```
     */
    public function directory(string $directory): static
    {
        $this->directory = $directory;
        $this->schema = null;

        return $this;
    }

    /**
     * Схема подполей (правила с префиксом `seo.`).
     */
    public function schema(): FormSchema
    {
        return $this->schema ??= $this->itemSchema([
            Text::make('title', (string) __('fastleo::seo.title', locale: Fastleo::LOCALE))->maxLength(255)->help((string) __('fastleo::seo.title_help', locale: Fastleo::LOCALE)),
            Textarea::make('description', (string) __('fastleo::seo.description', locale: Fastleo::LOCALE))->rows(3)->maxLength(500)->help((string) __('fastleo::seo.description_help', locale: Fastleo::LOCALE)),
            Image::make('image', (string) __('fastleo::seo.image', locale: Fastleo::LOCALE))->directory($this->directory)->help((string) __('fastleo::seo.image_help', locale: Fastleo::LOCALE)),
            Toggle::make('noindex', (string) __('fastleo::seo.noindex', locale: Fastleo::LOCALE))->help((string) __('fastleo::seo.noindex_help', locale: Fastleo::LOCALE)),
        ], $this->name.'.');
    }

    /**
     * Записываемые подполя.
     *
     * @return list<Field>
     */
    public function itemFields(): array
    {
        return self::writableItemFields($this->schema());
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Значения для формы — модель-обёртка с сохранёнными значениями.
     */
    #[Override]
    public function hydrate(Model $record): JsonItem
    {
        return JsonItem::from($this->stored($record->getAttribute($this->name)));
    }

    #[Override]
    protected function formatDefault(mixed $value): JsonItem
    {
        return JsonItem::from($this->stored($value));
    }

    /**
     * Правила поля и подполей (`seo.title`).
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);

        foreach ($this->itemFields() as $field) {
            foreach ($field->getRules(null) as $key => $fieldRules) {
                $rules[$this->name.'.'.$key] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['array'];
    }

    /**
     * Подписи подполей для текстов ошибок.
     *
     * @return array<string, string>
     */
    #[Override]
    public function validationAttributes(): array
    {
        $attributes = parent::validationAttributes();

        foreach ($this->schema()->fields() as $field) {
            foreach ($field->validationAttributes() as $key => $label) {
                $attributes[$this->name.'.'.$key] = $this->getLabel().': '.mb_strtolower($label);
            }
        }

        return $attributes;
    }

    /**
     * Значения из запроса: только описанные подполя, пустые отброшены.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $values = self::dehydrateItem($this->itemFields(), is_array($value) ? $value : []);

        return array_filter($values, fn (mixed $item): bool => ! in_array($item, [null, '', false], true));
    }

    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        $this->assertArrayCast($record);

        parent::fill($record, $value);
    }

    /**
     * Варианты новой картинки.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        if (! $record->wasRecentlyCreated && ! $record->wasChanged($this->name)) {
            return;
        }

        $previous = $record->wasRecentlyCreated ? [] : [$this->stored($record->getPrevious()[$this->name] ?? null)];

        self::queueItemVariants($this->itemFields(), [$this->stored($value)], $previous);
    }

    /**
     * На просмотре — заголовок, описание и пометка о запрете индексации.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof \Closure) {
            return parent::display($record);
        }

        $values = $this->stored($record->getAttribute($this->name));
        $html = '';

        foreach (['title' => 'font-medium', 'description' => 'text-ink-2'] as $key => $class) {
            if (is_string($values[$key] ?? null) && $values[$key] !== '') {
                $html .= '<div class="'.$class.'">'.e($values[$key]).'</div>';
            }
        }

        if (($values['noindex'] ?? false) === true) {
            $html .= '<div class="text-warning">'.e((string) __('fastleo::seo.noindex_shown', locale: Fastleo::LOCALE)).'</div>';
        }

        return $html === '' ? null : new HtmlString('<div class="flex flex-col gap-1 whitespace-normal">'.$html.'</div>');
    }

    /**
     * Сохранённое значение (объект или json-строка) → массив.
     *
     * @return array<array-key, mixed>
     */
    private function stored(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Пустое значение здесь имеет свой смысл (галочка, пароль, файл, список, вложенные записи) — `emptyValue()` недоступен.
     */
    #[Override]
    protected function supportsEmptyValue(): bool
    {
        return false;
    }
}
