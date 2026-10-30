<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Fastleo\Fastleo;
use Fastleo\Support\HtmlSanitizer;
use LogicException;

/**
 * Настройки чистки HTML сайта — ключ `fastleo.rich_text` в config/fastleo.php: дополнительные теги, атрибуты,
 * адреса плееров для iframe и оформление `style`. Опасное (скрипты, обработчики `on*`, директивы `x-`/`hx-`/`v-`/`ng-`, `srcdoc`, `javascript:`)
 * разрешить нельзя — такая настройка вызывает ошибку, `fastleo:doctor` показывает её сразу.
 *
 * ```php
 * 'rich_text' => [
 *     'tags' => ['div', 'span'],
 *     'attributes' => ['class', 'role', 'align', 'data-lpu'],
 *     'iframe_hosts' => ['https://prodoctorov.ru/'],
 *     'styles' => true,
 *     'content_css' => ['/css/app.css'],
 *     'content_style' => 'img { max-height: 4rem; }',
 * ],
 * ```
 */
final class RichTextSettings
{
    /** Приставки атрибутов, которые исполняют код: директивы Alpine админки, htmx, Vue, Angular. */
    private const array FRAMEWORK_PREFIXES = ['x-', 'hx-', 'data-hx-', 'v-', 'ng-'];

    /**
     * Дополнительные теги (кроме удаляемых вместе с содержимым).
     *
     * @return list<string>
     *
     * @throws LogicException недопустимая настройка
     */
    public static function tags(): array
    {
        return self::list('tags', fn (string $tag): bool => preg_match('/^[a-z][a-z0-9]*$/', $tag) === 1 && ! in_array($tag, HtmlSanitizer::DROPPED, true) && ! in_array($tag, ['html', 'body'], true));
    }

    /**
     * Дополнительные атрибуты на всех разрешённых тегах (точные имена, например `data-lpu`).
     *
     * @return list<string>
     *
     * @throws LogicException недопустимая настройка
     */
    public static function attributes(): array
    {
        return self::list('attributes', fn (string $attribute): bool => preg_match('/^[a-z][a-z0-9-]*$/', $attribute) === 1
            && ! str_starts_with($attribute, 'on')
            && ! array_any(self::FRAMEWORK_PREFIXES, fn (string $prefix): bool => str_starts_with($attribute, $prefix))
            && ! in_array($attribute, ['srcdoc', 'style', 'formaction'], true));
    }

    /**
     * Начала адресов плееров для iframe: `https://хост/путь/` или `https://хост/путь?`.
     *
     * @return list<string>
     *
     * @throws LogicException недопустимая настройка
     */
    public static function iframeHosts(): array
    {
        return self::list('iframe_hosts', fn (string $host): bool => preg_match('#^https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}/[A-Za-z0-9._~%/-]*\??$#', $host) === 1
            && (str_ends_with($host, '/') || str_ends_with($host, '?')));
    }

    /**
     * Сохранять `style` (без опасных значений) вместо одного `text-align`.
     */
    public static function styles(): bool
    {
        return config('fastleo.rich_text.styles', false) === true;
    }

    /**
     * Таблицы стилей сайта для области редактирования: `/css/app.css` или `https://хост/путь.css`.
     *
     * @return list<string>
     *
     * @throws LogicException недопустимая настройка
     */
    public static function contentCss(): array
    {
        return self::list('content_css', fn (string $url): bool => preg_match('#^(/(?![/\\\\])|https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(:\d+)?/)[^\s"\'<>\\\\]*$#i', $url) === 1);
    }

    /**
     * Свои правила CSS для области редактирования: `img { max-height: 4rem; }`; `null` — не задано.
     *
     * @throws LogicException недопустимая настройка
     */
    public static function contentStyle(): ?string
    {
        $style = config('fastleo.rich_text.content_style');

        if ($style === null || $style === '') {
            return null;
        }

        if (! is_string($style) || preg_match('#</#', $style) === 1) {
            throw new LogicException(trans()->string('fastleo::exceptions.rich_text_config', ['key' => 'content_style', 'value' => is_scalar($style) ? (string) $style : gettype($style)], Fastleo::LOCALE));
        }

        return $style;
    }

    /**
     * Проверить все настройки сразу.
     *
     * @throws LogicException недопустимая настройка
     */
    public static function validate(): void
    {
        self::tags();
        self::attributes();
        self::iframeHosts();
        self::contentCss();
        self::contentStyle();
    }

    /**
     * Список из конфига; недопустимое значение — ошибка с именем ключа и значением.
     *
     * @param  callable(string): bool  $valid
     * @return list<string>
     */
    private static function list(string $key, callable $valid): array
    {
        $values = config('fastleo.rich_text.'.$key, []);
        $result = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_string($value) || ! $valid($value)) {
                throw new LogicException(trans()->string('fastleo::exceptions.rich_text_config', ['key' => $key, 'value' => is_scalar($value) ? (string) $value : gettype($value)], Fastleo::LOCALE));
            }

            $result[] = $value;
        }

        return $result;
    }
}
