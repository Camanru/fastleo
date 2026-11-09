<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;

/**
 * Оформление панели из config('fastleo.brand'), config('fastleo.theme') и config('fastleo.css').
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
     * Название панели: из конфига, иначе название сайта, иначе адрес сайта из `app.url` (имя пакета сайту не навязывается;
     * адрес из конфига, а не из запроса: подпись в приложении 2FA не зависит от www и чужого заголовка Host).
     *
     * ```php
     * Branding::name(); // 'Мой сайт'
     * ```
     */
    public static function name(): string
    {
        $url = config('app.url');

        foreach ([config('fastleo.brand.name'), config('app.name'), is_string($url) ? parse_url($url, PHP_URL_HOST) : null] as $name) {
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return request()->getHost();
    }

    /**
     * Заголовок формы входа (`brand.login.heading`), по умолчанию «Вход».
     */
    public static function loginHeading(): string
    {
        $heading = config('fastleo.brand.login.heading');

        return is_string($heading) && $heading !== '' ? $heading : __('fastleo::auth.heading', [], Fastleo::LOCALE);
    }

    /**
     * Строка под заголовком формы входа (`brand.login.text`); по умолчанию нет (null) — название и так стоит над формой.
     */
    public static function loginText(): ?string
    {
        $text = config('fastleo.brand.login.text');

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * Строка внизу страниц без входа (`brand.login.footer`: вход, пароль, второй шаг, ошибки без входа), например
     * «© ООО Ромашка»; по умолчанию нет (null).
     */
    public static function loginFooter(): ?string
    {
        $footer = config('fastleo.brand.login.footer');

        return is_string($footer) && $footer !== '' ? $footer : null;
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
