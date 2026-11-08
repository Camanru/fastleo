<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

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
     *
     * Сбой попадает в журнал ошибок; `$report = false` — когда вызов повторяется для тысяч записей (импорт) и одинаковая
     * ошибка заполнила бы журнал.
     */
    public static function explain(QueryException $exception, bool $report = true): string
    {
        $state = $exception->errorInfo[0] ?? $exception->getCode();
        $state = is_scalar($state) ? (string) $state : '';
        $message = self::driverMessage($exception);

        $reason = match (true) {
            $exception instanceof UniqueConstraintViolationException => 'unique',
            $state === '23503' || stripos($message, 'foreign key') !== false => 'foreign',
            $state === '23502' || stripos($message, 'not null') !== false || stripos($message, 'cannot be null') !== false => 'not_null',
            $state === '22001' || stripos($message, 'too long') !== false => 'too_long',
            default => 'other',
        };

        if ($report) {
            report($exception);
        }

        $columns = $reason === 'unique' ? self::uniqueColumns($exception) : null;

        return $columns === null
            ? __('fastleo::resources.database_'.$reason)
            : __('fastleo::resources.database_unique_columns', ['columns' => $columns]);
    }

    /**
     * Текст ошибки драйвера без SQL и значений запроса: в `getMessage()` лежат введённые администратором значения,
     * слово «foreign key» в поле формы не должно менять причину ошибки.
     */
    private static function driverMessage(QueryException $exception): string
    {
        $message = $exception->errorInfo[2] ?? null;

        return is_string($message) ? $message : $exception->getMessage();
    }

    /**
     * Колонки, по которым база отказала в уникальности, из текста драйвера: SQLite (`articles.slug`), PostgreSQL
     * (`Key (slug)=(…)`) и MySQL (имя индекса `articles_slug_unique` по умолчанию Laravel). Не нашлись — null.
     */
    private static function uniqueColumns(QueryException $exception): ?string
    {
        $message = self::driverMessage($exception);

        if (preg_match('/UNIQUE constraint failed: (.+?)(?: \(Connection|$)/', $message, $match) === 1) {
            return implode(', ', array_map(fn (string $column): string => Str::afterLast(trim($column), '.'), explode(',', $match[1])));
        }

        if (preg_match('/Key \(([^)]+)\)=/', $message, $match) === 1) {
            return str_replace('"', '', $match[1]);
        }

        if (preg_match('/for key [\'"](?:\w+\.)?(\w+)[\'"]/', $message, $index) === 1
            && preg_match('/^\s*(?:insert into|update)\s+[`"]?(\w+)/i', $exception->getSql(), $table) === 1
            && preg_match('/^'.preg_quote($table[1], '/').'_(\w+)_unique$/', $index[1], $column) === 1) {
            return $column[1];
        }

        return null;
    }
}
