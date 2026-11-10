<?php

declare(strict_types=1);

namespace Fastleo\Leads;

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
     * В ключе конфига непустая строка (класс).
     */
    private static function filled(string $key): bool
    {
        $value = config($key);

        return is_string($value) && $value !== '';
    }
}
