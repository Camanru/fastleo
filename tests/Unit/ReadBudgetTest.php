<?php

declare(strict_types=1);

use Fastleo\Imports\ImportFailed;
use Fastleo\Imports\ReadBudget;
use Illuminate\Support\Sleep;

/**
 * Строки, чтение каждой из которых занимает заданное время (как читалка, которой каждая ячейка стоит времени).
 *
 * @param  list<string>  $rows
 */
function slowRows(array $rows, int $microseconds): Iterator
{
    return new class($rows, $microseconds) extends ArrayIterator
    {
        /**
         * @param  list<string>  $rows
         */
        public function __construct(array $rows, private readonly int $microseconds)
        {
            parent::__construct($rows);
        }

        public function next(): void
        {
            Sleep::usleep($this->microseconds);

            parent::next();
        }
    };
}

it('gives the rows and their keys as they are while the time of reading is within the limit', function (): void {
    expect(iterator_to_array(ReadBudget::metered(slowRows(['a', 'b', 'c'], 1000), 60.0)))->toBe(['a', 'b', 'c'])
        ->and(iterator_to_array(ReadBudget::metered(new ArrayIterator([]), 60.0)))->toBe([]);
});

it('stops when the reader has spent more than the limit, and counts the time of reading only, not of the work done with the rows', function (): void {
    $taken = [];

    expect(function () use (&$taken): void {
        foreach (ReadBudget::metered(slowRows(range(1, 50), 20_000), 0.05) as $row) {
            $taken[] = $row;
        }
    })->toThrow(ImportFailed::class, 'Файл читается слишком долго (больше 0.05 с)');

    // Два-три шага по 20 мс укладываются в 50 мс, полсотни — нет.
    expect(count($taken))->toBeGreaterThan(0)->toBeLessThan(10);

    $slowWork = [];

    foreach (ReadBudget::metered(slowRows(['a', 'b', 'c'], 0), 0.05) as $row) {
        Sleep::usleep(40_000);
        $slowWork[] = $row;
    }

    // Работа с каждой строкой заняла вдвое больше предела, но читалка тратила микросекунды.
    expect($slowWork)->toBe(['a', 'b', 'c']);
});
