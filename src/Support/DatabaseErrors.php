<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Понятный текст для ошибки базы данных, которую не поймала валидация формы
 * (уникальность, связь, обязательная колонка, длина).
 */
final class DatabaseErrors
{
    /**
     * Причина отказа базы простыми словами.
     *
     * ```php
     * DatabaseErrors::explain($exception); // 'Не удалось сохранить: такое значение уже есть у другой записи.'
     * ```
     */
    public static function explain(QueryException $exception): string
    {
        $state = $exception->errorInfo[0] ?? $exception->getCode();
        $state = is_scalar($state) ? (string) $state : '';
        $message = $exception->getMessage();

        $reason = match (true) {
            $exception instanceof UniqueConstraintViolationException => 'unique',
            $state === '23503' || stripos($message, 'foreign key') !== false => 'foreign',
            $state === '23502' || stripos($message, 'not null') !== false || stripos($message, 'cannot be null') !== false => 'not_null',
            $state === '22001' || stripos($message, 'too long') !== false => 'too_long',
            default => 'other',
        };

        report($exception);

        return __('fastleo::resources.database_'.$reason);
    }
}
