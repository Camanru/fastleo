<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;

/**
 * Иконки Lucide из пакета (resources/icons, обновляются скриптом `npm run icons`).
 *
 * В шаблонах используется компонент, имена — как на https://lucide.dev/icons:
 *
 * ```blade
 * <x-fastleo::icon name="stethoscope" class="size-5" />
 * ```
 */
final class Icons
{
    /**
     * Прочитанные SVG по имени — файл читается один раз за процесс.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * SVG-разметка иконки с атрибутами (class, title и т. п.) и aria-hidden.
     *
     * ```php
     * Icons::render('house', new ComponentAttributeBag(['class' => 'size-5']));
     * ```
     *
     * @throws InvalidArgumentException неизвестное или недопустимое имя иконки
     */
    public static function render(string $name, ?ComponentAttributeBag $attributes = null): HtmlString
    {
        $svg = str_replace('stroke-width="2"', 'stroke-width="1.75"', self::svg($name));
        $attributes = ($attributes ?? new ComponentAttributeBag)->merge(['aria-hidden' => 'true'])->toHtml();

        return new HtmlString((string) preg_replace('/^<svg\b/', '<svg '.$attributes, $svg, 1));
    }

    /**
     * Содержимое SVG-файла иконки как есть.
     *
     * @throws InvalidArgumentException неизвестное или недопустимое имя иконки
     */
    public static function svg(string $name): string
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        // Только безопасные имена: путь к файлу не выходит за папку иконок.
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) !== 1) {
            throw new InvalidArgumentException(__('fastleo::layout.icon_invalid', ['name' => $name], Fastleo::LOCALE));
        }

        $path = dirname(__DIR__, 2).'/resources/icons/'.$name.'.svg';

        if (! is_file($path)) {
            throw new InvalidArgumentException(__('fastleo::layout.icon_missing', ['name' => $name], Fastleo::LOCALE));
        }

        return self::$cache[$name] = trim((string) file_get_contents($path));
    }
}
