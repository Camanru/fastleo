<?php

declare(strict_types=1);

namespace Fastleo\Exports;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Выгрузка записей раздела в xlsx или csv: первая строка — подписи столбцов (ключ записи и поля ресурса),
 * дальше записи по 500 за запрос. Сначала читаются ключи записей в порядке списка, затем записи порциями
 * `whereKey()` — без смещения (`OFFSET`), поэтому порция из конца большой таблицы читается так же быстро, как первая.
 * Время выполнения PHP на выгрузку не ограничивается.
 *
 * csv отдаётся клиенту по мере записи, xlsx — после сборки файла целиком (zip собирается в конце), поэтому
 * для очень больших таблиц csv надёжнее: ответ не ждёт таймаута веб-сервера.
 *
 * Защита от формул: в xlsx текст всегда пишется текстовой ячейкой (`=СУММ(...)` не вычисляется),
 * в csv перед `= + - @`, табуляцией и переводом строки в начале значения ставится `'` (OWASP).
 * Строки-числа («007») остаются текстом.
 *
 * ```php
 * new Exporter($resource)->download($resource->newQuery(), 'xlsx');
 * ```
 */
final readonly class Exporter
{
    /** Форматы выгрузки. */
    public const array FORMATS = ['xlsx', 'csv'];

    public function __construct(private Resource $resource) {}

    /**
     * Поля в выгрузке: поля формы и списка, кроме `exportable(false)`, сложных (блоки, пароль) и скрытых.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return array_values(array_filter(
            $this->resource->schema()->fields(),
            fn (Field $field): bool => $field->isExportable() && ! $field->isHidden() && ($field->isFormField() || $field->isVisibleInList()),
        ));
    }

    /**
     * Подписи столбцов: ключ записи, затем подписи полей (у многоязычного — столбец на язык: «Заголовок (EN)»).
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return [$this->resource->newModel()->getKeyName(), ...array_column($this->columns($this->fields()), 0)];
    }

    /**
     * Столбцы полей: `[подпись, значение ячейки записи]` в порядке заголовка.
     *
     * @param  list<Field>  $fields
     * @return list<array{0: string, 1: \Closure(Model): (string|int|float|null)}>
     */
    private function columns(array $fields): array
    {
        $columns = [];

        foreach ($fields as $field) {
            if (! $field->isTranslatable()) {
                $columns[] = [$field->getLabel(), $field->exportValue(...)];

                continue;
            }

            foreach (Fastleo::locales() as $locale) {
                $columns[] = [$field->localeLabel($locale), fn (Model $record): ?string => $field->translations($record)[$locale] ?? null];
            }
        }

        return $columns;
    }

    /**
     * Ответ-скачивание файла с записями запроса.
     *
     * @param  Builder<Model>  $query
     * @param  'xlsx'|'csv'  $format
     */
    public function download(Builder $query, string $format): StreamedResponse
    {
        $fields = $this->fields();
        $relations = array_values(array_unique(array_merge(...array_map(fn (Field $field): array => $field->eagerLoad(), $fields))));
        $name = $this->resource::slug().'-'.now()->format('Y-m-d-His').'.'.$format;

        return response()->streamDownload(function () use ($query, $format, $fields, $relations): void {
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }

            $writer = $format === 'csv' ? new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';')) : new XlsxWriter;
            $writer->openToFile('php://output');
            $this->write($writer, $query->with($relations), $fields, $format === 'csv');
            $writer->close();
        }, $name, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Записать заголовок и строки.
     *
     * @param  Builder<Model>  $query
     * @param  list<Field>  $fields
     */
    private function write(WriterInterface $writer, Builder $query, array $fields, bool $csv): void
    {
        $bold = new Style(fontBold: true);
        $writer->addRow(new Row(array_map(fn (string $header): Cell => new StringCell($header, $bold), $this->headers())));
        $columns = $this->columns($fields);

        foreach (array_chunk($this->keys($query), 500) as $chunk) {
            $records = (clone $query)->reorder()->whereKey($chunk)->get()->keyBy(fn (Model $record): string => $this->keyString($record->getKey()));

            foreach ($chunk as $chunkKey) {
                $record = $records->get($this->keyString($chunkKey));

                if (! $record instanceof Model) {
                    continue;
                }

                $key = $record->getKey();
                $cells = [SpreadsheetCell::make(is_int($key) || is_string($key) ? $key : null, $csv)];

                foreach ($columns as [, $value]) {
                    $cells[] = SpreadsheetCell::make($value($record), $csv);
                }

                $writer->addRow(new Row($cells));
            }
        }
    }

    /**
     * Ключи записей запроса в порядке списка (поиск, фильтры, сортировка, корзина), без повторов, если запрос
     * ресурса соединяет таблицы. Сортировка по столбцу, который запрос ресурса задаёт сам через `select()` с
     * псевдонимом, здесь не работает: читается только ключ.
     *
     * @param  Builder<Model>  $query
     * @return list<int|string>
     */
    private function keys(Builder $query): array
    {
        $keys = [];

        foreach ((clone $query)->pluck($query->getModel()->getQualifiedKeyName()) as $key) {
            if (is_int($key) || is_string($key)) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * Ключ записи строкой: ключи из `pluck()` и `getKey()` могут отличаться типом (число и строка).
     */
    private function keyString(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }
}
