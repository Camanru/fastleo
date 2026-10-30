<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Fastleo\Fastleo;
use Fastleo\Fields\RichText;
use Fastleo\Resource;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

/**
 * Какие записи изменит чистка HTML при первом сохранении: сравнивает значение каждого поля RichText в базе
 * с результатом `sanitize()` буквально, поэтому видны и потерянные теги, и изменённые атрибуты, и поправленные
 * сущности (`&l`). Нужен команде `fastleo:doctor --sanitize-diff`. Проверяются поля верхнего уровня в изменяемых разделах.
 *
 * ```php
 * $report = new SanitizeDiff()->run();
 * // [['resource' => 'articles', 'field' => 'content', 'checked' => 70, 'changed' => 3, 'records' => [['key' => '12', 'locale' => null, 'before' => '…&l…', 'after' => '…&amp;l…']]]]
 * ```
 */
final class SanitizeDiff
{
    /** Сколько записей поля показывать в отчёте (остальные только считаются). */
    public const int LIMIT = 50;

    /** Сколько символов вокруг первого расхождения показывать. */
    public const int SNIPPET = 80;

    /** Размер порции записей. */
    private const int CHUNK = 500;

    /**
     * Отчёт по всем разделам; поля, где чистка ничего не меняет, в него не попадают.
     *
     * @return list<array{resource: string, field: string, checked: int, changed: int, records: list<array{key: string, locale: string|null, before: string, after: string}>}>
     *
     * @throws LogicException недопустимая настройка `fastleo.rich_text`
     */
    public function run(): array
    {
        $registry = Fastleo::resources();
        $report = [];

        foreach (array_keys($registry->all()) as $slug) {
            $resource = $registry->find($slug);

            if (! $resource instanceof Resource || $resource::isReadonly()) {
                continue;
            }

            try {
                $fields = $resource->schema()->fields();
            } catch (Throwable) {
                continue; // описание раздела doctor показывает ошибкой
            }

            foreach ($fields as $field) {
                if (! $field instanceof RichText || ! $field->isWritable() || ! $field->writesAttribute()) {
                    continue;
                }

                $row = $this->field($resource, $field);

                if ($row !== null && $row['changed'] > 0) {
                    $report[] = $row;
                }
            }
        }

        return $report;
    }

    /**
     * Строка сравнения одного поля или null, если таблицу не прочитать (ошибку показывает doctor).
     *
     * @return array{resource: string, field: string, checked: int, changed: int, records: list<array{key: string, locale: string|null, before: string, after: string}>}|null
     */
    private function field(Resource $resource, RichText $field): ?array
    {
        $model = $resource->newModel();
        $keyName = $model->getKeyName();
        $row = ['resource' => $resource::slug(), 'field' => $field->name, 'checked' => 0, 'changed' => 0, 'records' => []];

        try {
            $model->newQueryWithoutScopes()->toBase()->select([$keyName, $field->name])->whereNotNull($field->name)->chunkById(self::CHUNK, function (Collection $rows) use ($field, $keyName, &$row): void {
                foreach ($rows as $record) {
                    $this->record($field, $record->{$keyName}, $record->{$field->name}, $row);
                }
            }, $keyName);
        } catch (LogicException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /**
     * Сравнить значение одной записи (у многоязычного поля — каждый язык) и дополнить строку отчёта.
     *
     * @param  array{resource: string, field: string, checked: int, changed: int, records: list<array{key: string, locale: string|null, before: string, after: string}>}  $row
     */
    private function record(RichText $field, mixed $key, mixed $value, array &$row): void
    {
        $texts = [null => $value];

        if ($field->isTranslatable() && is_string($value)) {
            $decoded = json_decode($value, true);
            $texts = is_array($decoded) ? $decoded : $texts;
        }

        $changed = false;
        $counted = false;

        foreach ($texts as $locale => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $counted = true;
            $clean = $field->sanitize($text) ?? '';

            if ($clean === $text) {
                continue;
            }

            if (! $changed && count($row['records']) < self::LIMIT) {
                [$before, $after] = self::around($text, $clean);
                $row['records'][] = ['key' => is_scalar($key) ? (string) $key : '', 'locale' => $locale === '' ? null : (string) $locale, 'before' => $before, 'after' => $after];
            }

            $changed = true;
        }

        $row['checked'] += $counted ? 1 : 0;
        $row['changed'] += $changed ? 1 : 0;
    }

    /**
     * Куски обоих текстов вокруг первого расхождения; переводы строк заменены на `\n`.
     *
     * @return array{string, string}
     */
    public static function around(string $before, string $after): array
    {
        $limit = min(strlen($before), strlen($after));
        $at = 0;

        while ($at < $limit && $before[$at] === $after[$at]) {
            $at++;
        }

        // Расхождение внутри многобайтового символа — вернуться к его началу.
        while ($at > 0 && (ord($before[$at] ?? '') & 0xC0) === 0x80) {
            $at--;
        }

        $start = mb_strlen(substr($before, 0, $at)) - 20;
        $start = max(0, $start);

        $cut = static fn (string $text): string => ($start > 0 ? '…' : '').str_replace(["\r", "\n"], ['\r', '\n'], mb_substr($text, $start, self::SNIPPET)).(mb_strlen($text) > $start + self::SNIPPET ? '…' : '');

        return [$cut($before), $cut($after)];
    }
}
