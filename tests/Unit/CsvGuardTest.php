<?php

declare(strict_types=1);

use Fastleo\Imports\CsvGuard;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Наибольшее число полей в записи, которое насчитает сам `fgetcsv` читалки (OpenSpout: escape-символа нет).
 */
function fgetcsvWidest(string $content, string $delimiter): int
{
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $content);
    rewind($stream);

    $widest = 0;

    while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
        $widest = max($widest, count($row));
    }

    fclose($stream);

    return $widest;
}

it('counts the cells of a record the way fgetcsv does', function (string $content, string $delimiter, int $cells): void {
    expect(CsvGuard::widest($content, $delimiter))->toBe($cells)
        ->and(fgetcsvWidest($content, $delimiter))->toBe($cells);
})->with([
    'one cell' => ['a', ',', 1],
    'a plain line' => ["a,b,c\nd,e\n", ',', 3],
    'an empty line' => ["\n", ',', 1],
    'empty fields' => [',,,', ',', 4],
    'a line break inside quotes' => ["a,\"b\nc\",d\ne\n", ',', 3],
    'delimiters inside quotes' => ["\"a,b,c\",d\n", ',', 2],
    'a doubled quote inside quotes' => ["\"a\"\",b\",c\n", ',', 2],
    'a record of quoted line breaks' => ["\"\n\",\"\n\",\"\n\"\n", ',', 3],
    'a stray quote in the middle of an unquoted field' => ["x\"y,a,b\nc\n", ',', 3],
    'a stray quote after a closing quote' => ["\"a\"b\"c,d\ne,f\n", ',', 2],
    'spaces before a quote' => ["  \"a,b\"  ,c\n", ',', 2],
    'a quote that is never closed' => ["a,\"b,c\nd,e\nf", ',', 2],
    'windows line ends' => ["a;b;c\r\nd;e\r\n", ';', 3],
    'a lone carriage return is not a line end' => ["a,b\rc,d,e\n", ',', 4],
    'a tab as the delimiter and a space before a quote' => ["a\t \"b\tc\"\td\n", "\t", 3],
]);

it('counts the cells of the records of random text the way fgetcsv does, whatever it does with quotes and spaces', function (string $delimiter, array $alphabet): void {
    $random = new Randomizer(new Mt19937(2026));

    foreach (range(1, 600) as $case) {
        $content = '';

        foreach (range(1, $random->getInt(1, 80)) as $unused) {
            $content .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
        }

        if (fgetcsvWidest($content, $delimiter) === 0) {
            continue;
        }

        expect(CsvGuard::widest($content, $delimiter))->toBe(fgetcsvWidest($content, $delimiter), json_encode($content, JSON_UNESCAPED_UNICODE));
    }
})->with([
    'comma' => [',', [',', '"', 'a', ' ', "\n", "\r", "\t", "\x0b", "\x0c", 'б']],
    'semicolon' => [';', [';', '"', 'a', ' ', "\n", "\r\n", "\t"]],
    'tab' => ["\t", ["\t", '"', 'a', ' ', "\n", ',', "\x0b"]],
]);
