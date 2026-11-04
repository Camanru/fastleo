<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Перенос записи в ручном порядке (`$sortColumn`): стрелки «выше» / «ниже» и перетаскивание. Общий для списка раздела
 * и связанной таблицы: они различаются только тем, какие записи считаются соседями.
 */
final class ManualOrder
{
    /**
     * Переставить запись среди соседей по запросу: `direction` = `up` или `down` (стрелки) либо `before` / `after` = ключ
     * соседа (перетаскивание). Соседи получают свои прежние значения колонки по возрастанию (в одной транзакции), а не
     * номера 1..n: записи других родителей, лежащие в той же колонке, не меняются. Пустое значение считается 0;
     * если номера у соседей повторяются, они перенумеровываются 1..n.
     *
     * @param  Collection<int, Model>  $siblings  соседи вместе с самой записью в текущем порядке
     * @return bool false — соседа, рядом с которым надо встать, среди записей нет или сохранение соседа отменил observer (ответ 422, порядок не меняется)
     *
     * @throws QueryException ошибка базы при записи номеров
     */
    public static function move(Resource $resource, Model $record, Collection $siblings, Request $request): bool
    {
        $column = (string) $resource::sortColumn();
        $siblings = $siblings->keyBy(fn (Model $sibling): string => self::key($sibling));
        $keys = array_map(strval(...), $siblings->keys()->all());
        $key = self::key($record);
        $current = (int) array_search($key, $keys, true);
        array_splice($keys, $current, 1);

        $target = match (true) {
            $request->input('direction') === 'up' => max(0, $current - 1),
            $request->input('direction') === 'down' => min(count($keys), $current + 1),
            is_scalar($request->input('before')) => array_search((string) $request->input('before'), $keys, true),
            is_scalar($request->input('after')) => ($position = array_search((string) $request->input('after'), $keys, true)) === false ? false : $position + 1,
            default => false,
        };

        if ($target === false) {
            return false;
        }

        array_splice($keys, $target, 0, [$key]);

        $values = $siblings->map(fn (Model $sibling): int => self::orderOf($sibling, $column))->sort()->values()->all();

        // Повторяющиеся номера (импорт, нули по умолчанию) порядок не задают: тогда нумеруем 1..n.
        if (count(array_unique($values)) !== count($values)) {
            $values = range(1, count($values));
        }

        try {
            DB::connection($record->getConnectionName())->transaction(function () use ($keys, $siblings, $column, $values): void {
                foreach ($keys as $index => $siblingKey) {
                    $sibling = $siblings->get($siblingKey);

                    if ($sibling instanceof Model) {
                        $sibling->setAttribute($column, $values[$index]);

                        // Observer соседа отменил сохранение: порядок не должен измениться наполовину.
                        if ($sibling->isDirty($column) && ! $sibling->save()) {
                            throw new OperationCancelled();
                        }
                    }
                }
            });
        } catch (OperationCancelled) {
            return false;
        }

        return true;
    }

    /**
     * Текущий номер порядка записи; пустое или нечисловое значение — 0.
     */
    private static function orderOf(Model $record, string $column): int
    {
        $value = $record->getAttribute($column);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Ключ записи строкой.
     */
    public static function key(Model $record): string
    {
        $key = $record->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
