<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Illuminate\Support\Str;

/**
 * Включены ли встроенные «Заявки» (`fastleo.leads`): раздел, форма сайта и приём по API.
 *
 * ```php
 * Leads::enabled(); // задан fastleo.leads.resource
 * ```
 */
final class Leads
{
    /**
     * Раздел «Заявки» включён: в `fastleo.leads.resource` указан класс.
     */
    public static function enabled(): bool
    {
        return self::filled('fastleo.leads.resource');
    }

    /**
     * Принимается форма сайта (`POST /leads`): заявки включены и `fastleo.leads.form` не выключен.
     */
    public static function formEnabled(): bool
    {
        return self::enabled() && config()->boolean('fastleo.leads.form', true);
    }

    /**
     * Принимаются заявки по API (`POST /api/leads`): заявки включены и в `fastleo.leads.keys` указан класс.
     */
    public static function apiEnabled(): bool
    {
        return self::enabled() && self::keysClass() !== null;
    }

    /**
     * Класс раздела «Доступы для сайтов»: `fastleo.leads.keys`; если ключа в конфиге сайта нет (блок `leads` написан только с `resource`,
     * а конфиг пакета сливается по первому уровню), берётся `LeadApiKeyResource`; явный `null` выключает приём по API.
     *
     * @return class-string|null
     */
    public static function keysClass(): ?string
    {
        $value = config('fastleo.leads.keys', LeadApiKeyResource::class);

        /** @var class-string|null $class */
        $class = is_string($value) && $value !== '' ? $value : null;

        return $class;
    }

    /**
     * Якорь формы на странице (`<x-fastleo::lead-form anchor="hero" />`): латиница в нижнем регистре, цифры и дефис, первая буква,
     * до 40 знаков. Из него строятся `id` формы, набор ошибок и фрагмент адреса возврата, поэтому всё остальное отбрасывается.
     *
     * ```php
     * Leads::anchor('hero');      // 'hero'
     * Leads::anchor("a\nb");      // null
     * ```
     */
    public static function anchor(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^[a-z][a-z0-9-]{0,39}$~D', $value) === 1 ? $value : null;
    }

    /**
     * Название формы или тема заявки для поля «Форма»: одна строка до 100 знаков без управляющих и невидимых символов
     * (в том числе знаков смены направления письма); пусто или не строка — `null`.
     *
     * ```php
     * Leads::formName("  Тариф\n Бизнес ");   // 'Тариф Бизнес'
     * ```
     */
    public static function formName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = Str::of((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', ' ', $value))->squish()->limit(100, '')->toString();

        return $name === '' ? null : $name;
    }

    /**
     * В ключе конфига непустая строка (класс).
     */
    private static function filled(string $key): bool
    {
        $value = config($key);

        return is_string($value) && $value !== '';
    }
}
