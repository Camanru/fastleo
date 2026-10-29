<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Итог действия: сообщение над списком, переход на другой адрес или ответ (скачивание файла).
 *
 * ```php
 * return ActionResult::success("Опубликовано: {$records->count()}");
 * return ActionResult::error('Сервис рассылки недоступен');
 * return ActionResult::redirect(route('fastleo.resources.index', 'orders'), 'Заказ создан');
 * return ActionResult::download(response()->streamDownload(fn () => print $csv, 'orders.csv'));
 * ```
 */
final readonly class ActionResult
{
    /**
     * @param  'success'|'error'  $type
     */
    private function __construct(
        public string $type,
        public ?string $message = null,
        public ?string $redirect = null,
        public ?Response $response = null,
    ) {}

    /**
     * Действие выполнено: зелёное сообщение.
     */
    public static function success(string $message): self
    {
        return new self('success', $message);
    }

    /**
     * Действие не выполнено: красное сообщение.
     */
    public static function error(string $message): self
    {
        return new self('error', $message);
    }

    /**
     * Перейти на адрес (с сообщением или без).
     */
    public static function redirect(string $url, ?string $message = null): self
    {
        return new self('success', $message, $url);
    }

    /**
     * Отдать ответ как есть — например, скачивание файла.
     */
    public static function download(Response $response): self
    {
        return new self('success', response: $response);
    }
}
