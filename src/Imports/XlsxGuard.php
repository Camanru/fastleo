<?php

declare(strict_types=1);

namespace Fastleo\Imports;

use Fastleo\Fastleo;
use XMLReader;
use ZipArchive;

/**
 * Защита от «тяжёлых» xlsx, которые умещаются в лимит распакованного размера, но занимают воркер на часы.
 *
 * Читалка xlsx на каждой строке заводит массив ячеек во всю ширину (`dimension` листа или `spans` строки) и на каждую
 * ячейку копирует его целиком, поэтому время растёт как «ячейки × ширина», а `spans="1:900000000"` выделяет массив
 * на гигабайты. Настоящие таблицы сайта узкие: столбцов столько, сколько полей у раздела. Поэтому:
 * - в строке не больше `MAX_CELLS_PER_ROW` ячеек, а ширина (`dimension`, `spans`, адрес ячейки) — не дальше
 *   последнего столбца Excel;
 * - номер строки (`r`) и число строк не больше, чем вмещает Excel: читалка отдаёт каждую пустую строку между занятыми,
 *   и строка `r="9000000000000"` превращала бы архив в 1,4 КБ в миллионы пустых строк;
 * - оценка работы читалки по всему файлу (`MAX_WORK`) не больше двух минут: узкие строки с ячейками во всю ширину
 *   дёшевы поодиночке, но не по миллиону штук. Оценка считает ячейки там же, где их считает читалка, — и вне `<row>`;
 *   в неё входят и вставки текста `<t>` внутри одной ячейки или одной общей строки (читалка перебирает их живым
 *   списком DOM, и время растёт как квадрат их числа: 25 000 вставок в ячейке — 7 секунд, миллион — часы);
 * - в архиве не больше `MAX_ENTRIES` записей, в книге не больше `MAX_SHEETS` листов и `MAX_RELATIONSHIPS` связей: защита
 *   открывает архив заново для каждой записи, а читалка перечитывает все связи книги для каждого листа, так что время
 *   растёт как квадрат их числа;
 * - каждое имя в архиве читается один раз: `zip://` отдаёт первую запись с этим именем, а дубли в оглавлении
 *   заставляли бы защиту N раз читать один и тот же большой файл.
 *
 * Чего защита не знает, ограничивает время чтения в самом импорте (`Importer`, `import.max_read_seconds`):
 * например, при большом числе общих строк читалка держит их во временных файлах и открывает нужный на каждую ячейку.
 *
 * ```php
 * XlsxGuard::assertSafe($path, $zip); // ImportFailed, если файл слишком широкий, высокий или тяжёлый
 * ```
 */
final class XlsxGuard
{
    /** Наибольшее число ячеек в одной строке. */
    public const int MAX_CELLS_PER_ROW = 1000;

    /** Последний столбец Excel (XFD). */
    public const int MAX_COLUMN = 16384;

    /** Последняя строка Excel. */
    public const int MAX_ROW = 1_048_576;

    /** Наибольшее число записей в архиве: в настоящем xlsx их десятки, а защита открывает архив заново для каждой записи. */
    public const int MAX_ENTRIES = 1000;

    /** Наибольшее число листов в книге: читалка перечитывает все связи книги для каждого листа. */
    public const int MAX_SHEETS = 256;

    /** Наибольшее число связей книги (`workbook.xml.rels`), которые читалка перечитывает для каждого листа. */
    public const int MAX_RELATIONSHIPS = 1000;

    /**
     * Наибольшая оценка работы читалки над файлом. Единица — копирование одного элемента массива ячеек (замер:
     * около 0,06 мкс), так что предел — примерно две минуты. Таблица из 50 000 строк по 100 столбцов (1,3 · 10⁹) проходит.
     */
    public const int MAX_WORK = 2_000_000_000;

    /** Стоимость ячейки сверх копирования массива: разбор и значение (замер: около 12 мкс, то есть 200 единиц). */
    private const int CELL_COST = 200;

    /** Стоимость вставки текста `<t>` в ячейке или общей строке: она растёт вместе с числом вставок (замер: 0,2 единицы на квадрат числа). */
    private const int RUN_COST = 1;

    /** Стоимость заполнения массива ячеек строки: в начале строки по ширине и в конце по наибольшему столбцу. */
    private const int FILL_COST = 4;

    /**
     * Проверить все файлы архива, а не только `*.xml`: лист читалка берёт по пути из связей книги, и у него может быть
     * любое имя и расширение (в том числе с «#»: `zip://` открывает и такие). Строки (`row`) и ячейки (`c`) не должны
     * выходить за пределы, а общая оценка работы читалки — за `MAX_WORK`.
     *
     * @throws ImportFailed в строке слишком много ячеек, столбец или строка дальше последних строки и столбца Excel, файл слишком тяжёлый
     */
    public static function assertSafe(string $path, ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new ImportFailed(__('fastleo::imports.too_heavy', [], Fastleo::LOCALE));
        }

        $previous = libxml_use_internal_errors(true);
        $work = 0;
        $seen = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                // Открывается всегда первая запись с этим именем: остальные читалке недоступны, а читать их незачем.
                if (! isset($seen[$name])) {
                    $seen[$name] = true;

                    self::assertEntry('zip://'.$path.'#'.$name, $work);
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Прочитать один XML-файл потоком, прибавляя оценку работы читалки к `$work`; битый XML не ошибка защиты —
     * его не откроет и читалка xlsx.
     *
     * Читалка не смотрит, где стоит `<c>`: ячейка вне `<row>` попадает в текущий массив строки так же, как внутри неё
     * (после `</row>` массив начинается заново). Поэтому и ширина, и оценка считаются по ячейкам везде, а предел
     * `MAX_CELLS_PER_ROW` — только внутри строк: в `calcChain.xml` по `<c>` на каждую формулу, и это не таблица.
     * Ячейка без адреса `r` читалка ставит правее предыдущей, а счёт столбцов начинает заново только на `<row>`:
     * тысячи таких ячеек без строки растят массив и время чтения как квадрат своего числа, поэтому столбец такой
     * ячейки считается так же, и предел столбцов ограничивает их число.
     *
     * @throws ImportFailed
     */
    private static function assertEntry(string $uri, int &$work): void
    {
        $reader = new XMLReader();

        // Файл, который архив не отдаёт (запаролен, повреждён), не открывается и у читалки xlsx — она сообщит об этом сама.
        if (! @$reader->open($uri, null, LIBXML_NONET)) {
            return;
        }

        $inRow = false;
        $cells = 0;
        $rows = 0;
        $sheetWidth = 0;
        $width = 0;
        $previousColumn = 0;
        $runs = null;
        $sheets = 0;
        $relationships = 0;
        $isWorkbookRelations = str_ends_with($uri, 'workbook.xml.rels');

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT) {
                    if ($reader->localName === 'row') {
                        $inRow = false;
                        $work += self::FILL_COST * $width;
                        $width = 0;
                        $cells = 0;
                    } elseif ($reader->localName === 'c' || $reader->localName === 'si') {
                        $runs = null;
                    }

                    continue;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName === 'dimension') {
                    // Ссылка не диапазон — читалка оставляет ширину от предыдущего `<dimension>`.
                    $sheetWidth = self::lastColumn($reader->getAttribute('ref')) ?? $sheetWidth;

                    self::assertWithin($sheetWidth);
                } elseif ($reader->localName === 'row') {
                    self::assertRow(max(++$rows, (int) $reader->getAttribute('r')));

                    $previousColumn = 0;

                    $span = self::lastSpan($reader->getAttribute('spans'));

                    self::assertWithin($span);

                    // Заявленный размер листа читалка берёт для каждой строки вместо `spans`.
                    $width = $sheetWidth > 0 ? $sheetWidth : $span;
                    $work += self::FILL_COST * $width;
                    $inRow = ! $reader->isEmptyElement;
                    $cells = 0;

                    if (! $inRow) {
                        $work += self::FILL_COST * $width;
                        $width = 0;
                    }
                } elseif ($reader->localName === 't' && $runs !== null) {
                    $work += self::RUN_COST * ++$runs;
                } elseif ($reader->localName === 'c') {
                    $address = $reader->getAttribute('r');
                    $column = $address === null ? $previousColumn + 1 : self::column($address);
                    $previousColumn = $column;

                    self::assertWithin($column);

                    $width = max($width, $column);
                    $work += self::CELL_COST + $width;

                    if ($inRow) {
                        self::assertWithin(++$cells, self::MAX_CELLS_PER_ROW);
                    }

                    $runs = $reader->isEmptyElement ? null : 0;
                } elseif ($reader->localName === 'si') {
                    $runs = $reader->isEmptyElement ? null : 0;
                } elseif ($reader->localName === 'sheet') {
                    self::assertAtMost(++$sheets, self::MAX_SHEETS);
                } elseif ($isWorkbookRelations && $reader->localName === 'Relationship') {
                    self::assertAtMost(++$relationships, self::MAX_RELATIONSHIPS);
                }

                if ($work > self::MAX_WORK) {
                    throw new ImportFailed(__('fastleo::imports.too_heavy', [], Fastleo::LOCALE));
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Число не больше допустимого, иначе файл слишком широкий.
     *
     * @throws ImportFailed
     */
    public static function assertWithin(int $value, int $max = self::MAX_COLUMN): void
    {
        if ($value > $max) {
            throw new ImportFailed(__('fastleo::imports.too_wide', ['max' => $max], Fastleo::LOCALE));
        }
    }

    /**
     * Листов или связей книги не больше допустимого, иначе файл слишком тяжёлый для чтения.
     *
     * @throws ImportFailed
     */
    private static function assertAtMost(int $value, int $max): void
    {
        if ($value > $max) {
            throw new ImportFailed(__('fastleo::imports.too_heavy', [], Fastleo::LOCALE));
        }
    }

    /**
     * Строк в листе (или номер строки) не больше, чем вмещает Excel.
     *
     * @throws ImportFailed
     */
    private static function assertRow(int $row): void
    {
        if ($row > self::MAX_ROW) {
            throw new ImportFailed(__('fastleo::imports.too_tall', ['max' => number_format(self::MAX_ROW, 0, ',', ' ')], Fastleo::LOCALE));
        }
    }

    /**
     * Последний столбец из атрибута `spans` строки («1:5» → 5); 0, если атрибута нет.
     *
     * Читается так же, как читалка xlsx: вторая часть после «:» приводится к целому, поэтому «1:9e8», «1:+9» и
     * «1: 9» значат то же, что и «1:900000000»; регулярное выражение пропустило бы такие записи. Отрицательная
     * ширина — нуль: иначе часть архива, которую читалка не открывает, обнулила бы оценку работы над настоящим листом.
     */
    private static function lastSpan(?string $spans): int
    {
        return $spans === null ? 0 : max(0, (int) (explode(':', $spans)[1] ?? 0));
    }

    /**
     * Ширина листа из `<dimension ref="A1:E10"/>` («E10» → 5); `null`, если ссылка не диапазон.
     *
     * Читалка ищет диапазон только из заглавных букв и берёт первый найденный: «a1:b2 A1:XFE9» она читает как «A1:XFE9»,
     * поэтому и здесь тот же шаблон; иначе защита видела бы один диапазон, а читалка другой.
     */
    private static function lastColumn(?string $reference): ?int
    {
        if ($reference === null || preg_match('/[A-Z]+\d+:([A-Z]+\d+)/', $reference, $match) !== 1) {
            return null;
        }

        return self::column($match[1]);
    }

    /**
     * Номер столбца из адреса ячейки («B7» → 2); слишком длинные буквы — за пределом; 0, если адреса нет.
     */
    private static function column(?string $address): int
    {
        if ($address === null || preg_match('/^([A-Za-z]+)\d*$/', $address, $match) !== 1) {
            return 0;
        }

        if (strlen($match[1]) > 3) {
            return self::MAX_COLUMN + 1;
        }

        $number = 0;

        foreach (str_split(strtoupper($match[1])) as $letter) {
            $number = $number * 26 + (ord($letter) - 64);
        }

        return $number;
    }
}
