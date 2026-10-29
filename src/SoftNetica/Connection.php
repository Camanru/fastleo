<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Fastleo\Fastleo;
use Throwable;

/**
 * Подключение к SoftNetica: включено ли оно в конфиге, какой токен и адрес панели, подключён ли сайт сейчас.
 *
 * Сайт «подключён», если функция включена (`fastleo.softnetica.enabled`), в настройках сохранён токен и панель
 * его не отклонила. Пока сайт не подключён, пакет не делает ни одного запроса к SoftNetica.
 */
final class Connection
{
    /** Формат токена: `sn_` и 48 шестнадцатеричных символов. */
    public const string TOKEN_PATTERN = '/^sn_[0-9a-f]{48}$/';

    /**
     * Функция включена в конфиге.
     */
    public static function enabled(): bool
    {
        return config()->boolean('fastleo.softnetica.enabled', true);
    }

    /**
     * Токен из настроек (расшифрованный) или пустая строка, если токена нет или он неверного формата.
     */
    public static function token(): string
    {
        $token = Fastleo::setting('softnetica.token');
        $token = is_string($token) ? trim($token) : '';

        return preg_match(self::TOKEN_PATTERN, $token) === 1 ? $token : '';
    }

    /**
     * Токен введён и панель его не отклонила — можно слать отчёты и показывать данные.
     * Ошибка чтения настроек (нет таблицы, база недоступна) означает «не подключён».
     */
    public static function connected(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        try {
            $token = self::token();

            return $token !== '' && ! resolve(SyncState::class)->isRejected($token);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Адрес панели без слэша на конце или null, если он недопустим: нужен `https`, а `http` разрешён только при
     * `APP_ENV=local` (проверка против локальной панели). Адреса с логином, паролем, путём, параметрами не принимаются.
     */
    public static function endpoint(): ?string
    {
        $url = config('fastleo.softnetica.url');
        $parts = is_string($url) ? parse_url(rtrim(trim($url), '/')) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (($scheme !== 'https' && ($scheme !== 'http' || ! app()->isLocal()))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/^[a-z0-9.-]+$/i', $parts['host']) !== 1) {
            return null;
        }

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Хосты кабинета клиента, на которые разрешены ссылки из ответа панели (в нижнем регистре).
     *
     * @return list<string>
     */
    public static function cabinetHosts(): array
    {
        $hosts = config('fastleo.softnetica.cabinet_hosts');

        return is_array($hosts) ? array_values(array_filter(array_map(fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '', $hosts), fn (string $host): bool => $host !== '')) : [];
    }
}
