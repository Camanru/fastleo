<?php

declare(strict_types=1);

namespace Fastleo\Health;

/**
 * Результат своей проверки сайта (`Fastleo::healthCheck()`): всё хорошо, внимание или сбой.
 *
 * ```php
 * HealthResult::ok();
 * HealthResult::warning('Заявок нет уже 3 дня');
 * HealthResult::failed('Не удалось подключиться к CRM');
 * ```
 *
 * Текст уходит в отчёт для SoftNetica: не пишите в него пароли, ключи, адреса электронной почты, IP-адреса,
 * пути к файлам и ссылки.
 */
final readonly class HealthResult
{
    private function __construct(public string $status, public ?string $message) {}

    /**
     * Всё в порядке; поясняющий текст необязателен.
     */
    public static function ok(?string $message = null): self
    {
        return new self('ok', $message);
    }

    /**
     * Требует внимания, но сайт работает.
     */
    public static function warning(string $message): self
    {
        return new self('warning', $message);
    }

    /**
     * Сбой: функция сайта не работает.
     */
    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }
}
