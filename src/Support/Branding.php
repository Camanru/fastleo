<?php

declare(strict_types=1);

namespace Fastleo\Support;

/**
 * Оформление админки из config('fastleo.brand'), config('fastleo.theme') и config('fastleo.css').
 *
 * Значения проверяются: в шаблон попадает только допустимый цвет и тема.
 */
final class Branding
{
    /** Цвет по умолчанию, если в конфиге не HEX. */
    public const string DEFAULT_COLOR = '#4f46e5';

    /** Допустимые темы. */
    public const array THEMES = ['light', 'dark', 'system'];

    /**
     * Название админки: из конфига, иначе название сайта, иначе «Fastleo».
     *
     * ```php
     * Branding::name(); // 'Стоматология'
     * ```
     */
    public static function name(): string
    {
        foreach ([config('fastleo.brand.name'), config('app.name')] as $name) {
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return 'Fastleo';
    }

    /**
     * Адрес логотипа или null.
     */
    public static function logo(): ?string
    {
        $logo = config('fastleo.brand.logo');

        return is_string($logo) && $logo !== '' ? $logo : null;
    }

    /**
     * Основной цвет в HEX (#rgb, #rrggbb, #rrggbbaa); иное значение заменяется цветом по умолчанию,
     * чтобы в <style> нельзя было вставить произвольный CSS.
     */
    public static function color(): string
    {
        $color = config('fastleo.brand.color');

        return is_string($color) && preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1
            ? $color
            : self::DEFAULT_COLOR;
    }

    /**
     * Тема по умолчанию: light, dark или system.
     */
    public static function theme(): string
    {
        $theme = config('fastleo.theme');

        return is_string($theme) && in_array($theme, self::THEMES, true) ? $theme : 'system';
    }

    /**
     * Адреса дополнительных стилей сайта.
     *
     * @return list<string>
     */
    public static function stylesheets(): array
    {
        $css = config('fastleo.css');

        return is_array($css) ? array_values(array_filter($css, is_string(...))) : [];
    }
}
