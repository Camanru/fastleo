<?php

declare(strict_types=1);

namespace Fastleo\Imports;

use Fastleo\Fastleo;
use Generator;
use Iterator;

/**
 * Ограничение времени, которое импорт тратит на чтение строк файла (без записи в базу).
 *
 * Защита `XlsxGuard` оценивает чтение заранее, но оценка знает не всё: например, при большом числе общих строк
 * читалка держит их во временных файлах и на каждую ячейку открывает нужный, а какой кусок открывать, зависит
 * от файла. Поэтому время, потраченное самой читалкой (`rewind()` и `next()`), складывается по ходу импорта,
 * и импорт останавливается, когда сумма больше предела. Одна ячейка, которая читается часами, этим не прерывается:
 * её, как и весь импорт, ограничивает час задачи.
 *
 * ```php
 * foreach (ReadBudget::metered($sheet->getRowIterator(), 600.0) as $row) {
 *     // ...
 * }
 * ```
 */
final class ReadBudget
{
    /**
     * Строки файла как есть, пока время чтения не превысило `$seconds`.
     *
     * @template TKey
     * @template TRow
     *
     * @param  Iterator<TKey, TRow>  $rows
     * @return Generator<TKey, TRow>
     *
     * @throws ImportFailed время чтения вышло
     */
    public static function metered(Iterator $rows, float $seconds): Generator
    {
        $limit = (int) ($seconds * 1_000_000_000);
        $spent = 0;
        $started = hrtime(true);

        $rows->rewind();

        $spent += hrtime(true) - $started;

        while (true) {
            if ($spent > $limit) {
                throw new ImportFailed(__('fastleo::imports.read_too_long', ['seconds' => rtrim(rtrim(sprintf('%.3f', $seconds), '0'), '.')], Fastleo::LOCALE));
            }

            if (! $rows->valid()) {
                return;
            }

            yield $rows->key() => $rows->current();

            $started = hrtime(true);

            $rows->next();

            $spent += hrtime(true) - $started;
        }
    }
}
