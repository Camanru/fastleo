<?php

declare(strict_types=1);

namespace Fastleo\Exports;

/**
 * Форматы таблиц, которые доступны администратору в выгрузке и загрузке.
 *
 * Excel (xlsx) сейчас отключён настройкой `fastleo.xlsx` (`false`): код чтения и записи xlsx остаётся в пакете, но в админке
 * его нет — ни в кнопке выгрузки, ни в окне загрузки, ни в проверке загружаемого файла. Остаётся CSV.
 *
 * ```php
 * SpreadsheetFormats::all();     // ['csv']
 * SpreadsheetFormats::label();   // 'csv'
 * ```
 */
final class SpreadsheetFormats
{
    /**
     * Доступные форматы; первый — по умолчанию. Настройка читается как любое «да/нет» (`true`, `1`, `'true'`).
     *
     * @return list<'xlsx'|'csv'>
     */
    public static function all(): array
    {
        return filter_var(config('fastleo.xlsx', false), FILTER_VALIDATE_BOOLEAN) ? ['xlsx', 'csv'] : ['csv'];
    }

    /**
     * Формат по умолчанию: тот, в котором выгружается файл, если формат не выбран.
     *
     * @return 'xlsx'|'csv'
     */
    public static function preferred(): string
    {
        return self::all()[0];
    }

    /**
     * Форматы словами для сообщений: «csv» или «xlsx или csv».
     */
    public static function label(): string
    {
        return implode(' или ', self::all());
    }
}
