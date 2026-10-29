<?php

declare(strict_types=1);

namespace Fastleo\Imports;

use DateTimeInterface;
use Fastleo\Exports\SpreadsheetCell;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordKey;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ValidationMessages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use ZipArchive;

/**
 * Загрузка записей раздела из xlsx/csv: строка файла — запись, столбец — поле (по имени или подписи).
 *
 * Каждая строка проходит те же права, правила и запись, что и форма, в своей транзакции;
 * строка с ошибкой пропускается и попадает в отчёт (первые `Import::MAX_ERRORS`) и в xlsx-файл со всеми
 * пропущенными строками: столбцы исходного файла и «Ошибка» (`skippedFile()`). Пустые строки пропускаются молча.
 * CSV: кодировка UTF-8 или Windows-1251 (русский Excel), разделитель «;», «,» или табуляция.
 */
final class Importer
{
    /** Ячейка Excel вмещает не больше символов — длинный текст в файле обрезан. */
    private const int CELL_LIMIT = 32767;

    /** Как часто сохраняется прогресс (строк). */
    private const int PROGRESS_EVERY = 100;

    /**
     * Столбец файла → поле ресурса, язык многоязычного поля (`[поле, язык]`) или ключ записи (`true`).
     *
     * @var array<int, Field|array{0: Field, 1: string}|true>
     */
    private array $columns = [];

    /** Столбец своего ключа сопоставления (`$importKey`) или null — его нет в файле. */
    private ?int $importKeyColumn = null;

    /**
     * Тексты ошибок проверки: собираются один раз на файл, а не на каждую строку.
     *
     * @var array<string, string|array<string, string>>|null
     */
    private ?array $validationMessages = null;

    /**
     * Ячейки первой строки исходного файла.
     *
     * @var list<Cell>
     */
    private array $header = [];

    /** Писатель файла с пропущенными строками; создаётся при первой пропущенной строке. */
    private ?XlsxWriter $skippedWriter = null;

    /** Временный файл с пропущенными строками. */
    private ?string $skippedPath = null;

    public function __construct(private readonly Resource $resource, private readonly Import $import) {}

    /**
     * Временный xlsx с пропущенными строками (закрыт, готов к копированию) или null, если строк не пропущено.
     * Удалять файл должен вызывающий.
     */
    public function skippedFile(): ?string
    {
        return $this->skippedPath;
    }

    /**
     * Обработать файл (абсолютный путь). Ошибка файла целиком — исключение.
     *
     * @throws ImportFailed файл не читается или в нём нет известных столбцов
     */
    public function run(string $path, string $extension): void
    {
        $reader = $this->reader($path, $extension);
        $csv = $extension === 'csv';
        $max = max(1, config()->integer('fastleo.import.max_rows', 50000));
        $line = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(fn (mixed $cell): mixed => $csv ? $this->csvCell($cell) : $cell, $row->toArray());

                    if ($line === 1) {
                        $this->header = array_values(array_map($this->reportCell(...), $cells));
                        $this->mapColumns($cells);

                        continue;
                    }

                    if ($line - 1 > $max) {
                        $this->import->message = __('fastleo::imports.too_many_rows', ['max' => $max], Fastleo::LOCALE);

                        break 2;
                    }

                    $this->row($line, $cells);

                    if ($this->import->processed % self::PROGRESS_EVERY === 0) {
                        $this->import->save();
                    }
                }

                break; // только первый лист
            }
        } finally {
            $reader->close();
            $this->skippedWriter?->close();
            $this->skippedWriter = null;
        }

        if ($line === 0) {
            throw new ImportFailed(__('fastleo::imports.no_columns', locale: Fastleo::LOCALE));
        }
    }

    /**
     * Открыть файл: xlsx или csv с определением кодировки и разделителя.
     *
     * @throws ImportFailed файл не открывается
     */
    private function reader(string $path, string $extension): CsvReader|XlsxReader
    {
        try {
            if ($extension === 'csv') {
                $content = (string) file_get_contents($path);
                $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
                $firstLine = strtok($content, "\n") ?: '';
                $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
                arsort($counts);
                $delimiter = (string) array_key_first($counts);

                $reader = new CsvReader(new CsvOptions(
                    FIELD_DELIMITER: $counts[$delimiter] > 0 ? $delimiter : ',',
                    ENCODING: mb_check_encoding($content, 'UTF-8') ? 'UTF-8' : 'Windows-1251',
                ));
            } else {
                $this->assertUnpackedSizeIsSafe($path);
                $reader = new XlsxReader;
            }

            $reader->open($path);

            return $reader;
        } catch (ImportFailed $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ImportFailed(__('fastleo::imports.unreadable', locale: Fastleo::LOCALE));
        }
    }

    /**
     * Размер xlsx после распаковки не больше `import.max_unpacked` МБ: маленький архив может развернуться
     * в гигабайты и исчерпать память воркера. Размеры из оглавления архива пишет автор файла, поэтому
     * считаются байты, которые действительно получаются при распаковке.
     *
     * @throws ImportFailed файл не архив или слишком большой после распаковки
     */
    private function assertUnpackedSizeIsSafe(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFailed(__('fastleo::imports.unreadable', locale: Fastleo::LOCALE));
        }

        $limit = max(1, config()->integer('fastleo.import.max_unpacked', 200));
        $left = $limit * 1024 * 1024;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stream = $zip->getStreamIndex($index);

                if ($stream === false) {
                    continue;
                }

                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false) {
                        break;
                    }

                    $left -= strlen($chunk);

                    if ($left < 0) {
                        fclose($stream);

                        throw new ImportFailed(__('fastleo::imports.too_big', ['mb' => $limit], Fastleo::LOCALE));
                    }
                }

                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Сопоставить заголовки столбцов с полями (имя или подпись без учёта регистра) и ключом записи.
     *
     * @param  array<int, mixed>  $headers
     *
     * @throws ImportFailed нет ни одного известного столбца
     */
    private function mapColumns(array $headers): void
    {
        $importKey = $this->resource::importKey();
        $targets = [mb_strtolower($this->resource->newModel()->getKeyName()) => true];

        // Свой ключ ищется по имени колонки или подписи поля — даже если поле не загружается (readonly).
        if ($importKey !== null) {
            $names = array_filter([mb_strtolower($importKey), mb_strtolower($this->resource->schema()->field($importKey)?->getLabel() ?? '')]);

            foreach ($headers as $index => $header) {
                if (is_scalar($header) && in_array(mb_strtolower(trim((string) $header)), $names, true)) {
                    $this->importKeyColumn = $index;

                    break;
                }
            }
        }

        $main = Fastleo::locales()[0];

        foreach ($this->fields() as $field) {
            if ($field->isTranslatable()) {
                foreach (Fastleo::locales() as $locale) {
                    $targets[mb_strtolower($field->localeLabel($locale))] ??= [$field, $locale];
                    $targets[mb_strtolower($field->name.'.'.$locale)] ??= [$field, $locale];
                }

                // Столбец без языка («Заголовок», «title») — основной язык.
                $targets[mb_strtolower($field->getLabel())] ??= [$field, $main];
                $targets[mb_strtolower($field->name)] ??= [$field, $main];

                continue;
            }

            $targets[mb_strtolower($field->getLabel())] ??= $field;
            $targets[mb_strtolower($field->name)] ??= $field;
        }

        $unknown = [];
        $used = [];

        foreach ($headers as $index => $header) {
            $name = is_scalar($header) ? trim((string) $header) : '';
            $target = $targets[mb_strtolower($name)] ?? null;
            $id = match (true) {
                $target instanceof Field => $target->name,
                is_array($target) => $target[0]->name.'.'.$target[1],
                $target === true => "\0key",
                default => null,
            };

            if ($target === null || isset($used[$id])) {
                if ($name !== '' && $index !== $this->importKeyColumn) {
                    $unknown[] = $name;
                }

                continue;
            }

            $used[$id] = true;
            $this->columns[$index] = $target;
        }

        $this->import->unknown_columns = $unknown === [] ? null : $unknown;

        if (array_filter($this->columns, fn (Field|array|bool $target): bool => $target !== true) === []) {
            throw new ImportFailed(__('fastleo::imports.no_columns', locale: Fastleo::LOCALE));
        }
    }

    /**
     * Обработать строку файла.
     *
     * @param  array<int, mixed>  $cells
     */
    private function row(int $line, array $cells): void
    {
        $values = [];
        $key = $this->importKeyColumn === null ? null : $this->scalar($cells[$this->importKeyColumn] ?? null);

        foreach ($this->columns as $index => $target) {
            if ($target === true) {
                $key = $this->resource::importKey() === null ? $this->scalar($cells[$index] ?? null) : $key;
            } elseif (is_array($target)) {
                $translations = $values[$target[0]->name] ?? [];
                $translations = is_array($translations) ? $translations : [];
                $translations[$target[1]] = $cells[$index] ?? null;
                $values[$target[0]->name] = $translations;
            } else {
                $values[$target->name] = $cells[$index] ?? null;
            }
        }

        if (array_filter($values, $this->isFilled(...)) === [] && $key === null) {
            return; // пустая строка
        }

        $this->import->processed++;
        $error = $this->save($values, $key);

        if ($error !== null) {
            $this->import->skipped++;
            $this->import->addError($line, $error);
            $this->writeSkipped($cells, $error);
        }
    }

    /**
     * Дописать пропущенную строку в файл: исходные ячейки и текст ошибки в последнем столбце.
     *
     * @param  array<int, mixed>  $cells
     */
    private function writeSkipped(array $cells, string $error): void
    {
        if (! $this->skippedWriter instanceof XlsxWriter) {
            $this->skippedPath = (string) tempnam(sys_get_temp_dir(), 'fastleo-skipped');
            $this->skippedWriter = new XlsxWriter;
            $this->skippedWriter->openToFile($this->skippedPath);
            $bold = new Style(fontBold: true);
            $header = [...$this->header, new StringCell(__('fastleo::imports.error', locale: Fastleo::LOCALE), $bold)];
            $this->skippedWriter->addRow(new Row(array_map(fn (Cell $cell): Cell => $cell instanceof StringCell ? new StringCell($cell->getValue(), $bold) : $cell, $header)));
        }

        // Ячейки ровно по ширине заголовка: «Ошибка» всегда в своём столбце, даже если строка файла короче или длиннее.
        $cells = array_slice(array_pad(array_values($cells), count($this->header), null), 0, count($this->header));
        $this->skippedWriter->addRow(new Row([...array_map($this->reportCell(...), $cells), SpreadsheetCell::make($error)]));
    }

    /**
     * Ячейка исходного файла → ячейка отчёта: даты — текстом, числа и текст как есть, остальное — пусто.
     */
    private function reportCell(mixed $cell): Cell
    {
        return SpreadsheetCell::make(match (true) {
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d H:i:s'),
            is_string($cell), is_int($cell), is_float($cell) => $cell,
            is_bool($cell) => $cell ? '1' : '0',
            default => null,
        });
    }

    /**
     * В ячейке (или в одном из языков многоязычной ячейки) есть значение.
     */
    private function isFilled(mixed $cell): bool
    {
        return $this->scalar($cell) !== null || $cell instanceof DateTimeInterface
            || (is_array($cell) && array_filter($cell, fn (mixed $item): bool => $this->scalar($item) !== null) !== []);
    }

    /**
     * Найти или создать запись, проверить и сохранить. Возвращает текст ошибки или null.
     *
     * @param  array<string, mixed>  $values  ячейки по имени поля
     * @param  ?string  $key  ключ модели или значение своего ключа `$importKey`
     */
    private function save(array $values, ?string $key): ?string
    {
        $importKey = $this->resource::importKey();
        $model = $this->resource->newModel();
        $existing = null;

        if ($importKey === null && $key !== null) {
            $existing = RecordKey::valid($model, $key) ? $this->resource->newQuery()->whereKey($key)->first() : null;

            if (! $existing instanceof Model) {
                return __('fastleo::imports.record_missing', ['key' => $key], Fastleo::LOCALE);
            }
        } elseif ($importKey !== null && $key !== null) {
            $existing = $this->resource->newQuery()->where($model->qualifyColumn($importKey), $key)->first();
            $writesKey = in_array($importKey, array_map(fn (Field $field): string => $field->name, $this->fields()), true);

            // Ключ не загружается (readonly, нет в форме) — новая запись осталась бы без него и дублировалась бы.
            if (! $existing instanceof Model && ! $writesKey) {
                return __('fastleo::imports.record_missing', ['key' => $key], Fastleo::LOCALE);
            }

            // В строке только ключ, записи с ним нет: создавать пустую запись незачем (список ключей для обновления).
            if (! $existing instanceof Model && array_filter(array_diff_key($values, [$importKey => true]), $this->isFilled(...)) === []) {
                return __('fastleo::imports.record_missing', ['key' => $key], Fastleo::LOCALE);
            }
        }

        // Метод import() Policy проверяет только загрузку файла; строка — всегда права формы.
        if (! Authorization::allows($existing instanceof Model ? 'update' : 'create', $this->resource, $existing)) {
            return __('fastleo::imports.forbidden', locale: Fastleo::LOCALE);
        }

        $fields = [];
        $data = [];

        foreach ($this->fields($existing) as $field) {
            if (array_key_exists($field->name, $values)) {
                $value = $field->isTranslatable()
                    ? $this->translations($field, $values[$field->name], $existing)
                    : $field->importValue($values[$field->name]);

                if ($existing instanceof Model && $this->truncated($value, $existing->getAttribute($field->name))) {
                    continue;
                }

                $data[$field->name] = $value;
            } elseif ($existing instanceof Model) {
                continue; // столбца нет — поле записи не меняется
            } else {
                // Новая запись: значение по умолчанию поля; без него — значение базы (обязательное поле — ошибка
                // проверки). Поле всё равно участвует в записи: Slug заполнится из заголовка в beforeSave().
                $default = $field->formValue(null);

                if (! in_array($default, [null, '', []], true)) {
                    $data[$field->name] = $default;
                }
            }

            $fields[] = $field;
        }

        // Управляющего поля (visibleWhen, dependsOn) нет в файле — условие проверяется по значению записи.
        foreach ($this->resource->schema()->fields() as $field) {
            foreach (array_filter([$field->getVisibleWhen()['field'] ?? null, $field->getDependsOn()]) as $controlling) {
                if ($existing instanceof Model && ! array_key_exists($controlling, $data)) {
                    $data[$controlling] = $this->resource->controllingValue($controlling, $existing, null);
                }
            }
        }

        $input = RecordWriter::controllingInput($this->resource, $existing, $data);
        $validator = validator($input, RecordWriter::rules($this->resource, $existing, $fields), $this->validationMessages ??= ValidationMessages::all(), RecordWriter::attributes($fields));

        if ($validator->fails()) {
            return (string) $validator->errors()->first();
        }

        $record = $existing ?? $model;

        try {
            RecordWriter::write($this->resource, $record, $fields, RecordWriter::inInputOrder($validator->validated(), $input));
        } catch (QueryException $exception) {
            return DatabaseErrors::explain($exception);
        } catch (OperationCancelled) {
            return __('fastleo::resources.save_cancelled', locale: Fastleo::LOCALE);
        }

        $existing instanceof Model ? $this->import->updated++ : $this->import->created++;

        return null;
    }

    /**
     * Переводы многоязычного поля из ячеек языков: языки без столбца в файле сохраняют значение записи.
     *
     * @return array<string, string>
     */
    private function translations(Field $field, mixed $cells, ?Model $existing): array
    {
        $translations = $existing instanceof Model ? $field->translations($existing) : [];

        foreach (is_array($cells) ? $cells : [] as $locale => $cell) {
            $text = is_string($locale) ? $field->importValue($cell) : null;

            if (is_string($text)) {
                $translations[$locale] = $text;
            } elseif (is_string($locale)) {
                unset($translations[$locale]);
            }
        }

        return $translations;
    }

    /**
     * Поля, которые загружаются из файла: записываемые в форме (при обновлении — для этой записи)
     * и выгружаемые (`exportable`).
     *
     * @return list<Field>
     */
    private function fields(?Model $record = null): array
    {
        return array_values(array_filter($this->resource->writableFields($record), fn (Field $field): bool => $field->isExportable() && ! $field->isHidden($record)));
    }

    /**
     * Текст обрезан ячейкой Excel: в базе длиннее и начинается с него — значение записи не трогается.
     */
    private function truncated(mixed $value, mixed $stored): bool
    {
        return is_string($value) && is_string($stored) && mb_strlen($value) >= self::CELL_LIMIT
            && mb_strlen($stored) > mb_strlen($value) && str_starts_with($stored, $value);
    }

    /**
     * Значение CSV без защитного `'` перед формулой (так его пишет экспорт).
     */
    private function csvCell(mixed $cell): mixed
    {
        return is_string($cell) && preg_match("/^'[=+\\-@\\t\\r]/", $cell) === 1 ? substr($cell, 1) : $cell;
    }

    /**
     * Ячейка строкой или null (пусто).
     */
    private function scalar(mixed $cell): ?string
    {
        if (is_float($cell) && floor($cell) === $cell) {
            return (string) (int) $cell;
        }

        $value = is_scalar($cell) ? trim((string) $cell) : '';

        return $value === '' ? null : $value;
    }
}
