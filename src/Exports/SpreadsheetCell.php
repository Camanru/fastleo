<?php

declare(strict_types=1);

namespace Fastleo\Exports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;

/**
 * Значение → ячейка файла выгрузки. Текст — всегда текстовая ячейка (формула в xlsx не вычисляется);
 * в csv перед `= + - @`, табуляцией и переводом строки в начале значения ставится `'` (OWASP).
 */
final class SpreadsheetCell
{
    /**
     * ```php
     * SpreadsheetCell::make('=СУММ(A1)'); // текст «=СУММ(A1)», не формула
     * ```
     */
    public static function make(string|int|float|null $value, bool $csv = false): Cell
    {
        if ($value === null || $value === '') {
            return new EmptyCell(null);
        }

        if (! is_string($value)) {
            return new NumericCell($value);
        }

        if ($csv && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'".$value;
        }

        return new StringCell($value);
    }
}
