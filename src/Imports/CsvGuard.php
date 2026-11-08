<?php

declare(strict_types=1);

namespace Fastleo\Imports;

/**
 * Защита от «широких» csv, которые умещаются в лимит размера файла, но исчерпывают память воркера.
 *
 * Читалка отдаёт запись целиком: строка из миллиона запятых превращается в миллион ячеек (около 175 байт каждая), и то же
 * получается из записи, растянутой на много строк файла переводами строк в кавычках. Поэтому ячейки считаются по записям,
 * а не по строкам файла, и разбор записи повторяет `fgetcsv` читалки (кавычка без escape-символа, как в RFC 4180):
 * - поле начинается с кавычки, если перед ней только пробельные символы (`isspace` — пробел, `\t`, `\r`, `\v`, `\f`);
 *   кавычка в середине поля без кавычек — обычный символ;
 * - внутри кавычек разделители и переводы строк не считаются, а две кавычки подряд — одна кавычка в тексте;
 * - после закрывающей кавычки остаток поля до разделителя или конца строки читается как есть;
 * - запись заканчивается переводом строки вне кавычек; незакрытая кавычка тянется до конца файла.
 *
 * ```php
 * XlsxGuard::assertWithin(CsvGuard::widest($content, ';')); // ImportFailed, если в какой-то записи больше 16 384 ячеек
 * ```
 */
final class CsvGuard
{
    /**
     * Наибольшее число ячеек в одной записи файла.
     *
     * @param  string  $delimiter  разделитель из одного байта (`,`, `;` или табуляция)
     */
    public static function widest(string $content, string $delimiter): int
    {
        $blank = str_replace($delimiter, '', " \t\r\v\f");
        $stop = $delimiter."\n";
        $length = strlen($content);
        $position = 0;
        $cells = 1;
        $widest = 1;

        while ($position < $length) {
            $position += strspn($content, $blank, $position);

            if ($position < $length && $content[$position] === '"') {
                $position = self::afterEnclosed($content, $position + 1);
            }

            $position += strcspn($content, $stop, $position);

            if ($position >= $length) {
                break;
            }

            $cells = $content[$position] === $delimiter ? $cells + 1 : 1;
            $widest = max($widest, $cells);
            $position++;
        }

        return $widest;
    }

    /**
     * Позиция сразу после закрывающей кавычки поля (после открывающей, `$position`); две кавычки подряд — часть текста;
     * незакрытая кавычка — конец файла.
     */
    private static function afterEnclosed(string $content, int $position): int
    {
        while (($quote = strpos($content, '"', $position)) !== false) {
            if (($content[$quote + 1] ?? '') !== '"') {
                return $quote + 1;
            }

            $position = $quote + 2;
        }

        return strlen($content);
    }
}
