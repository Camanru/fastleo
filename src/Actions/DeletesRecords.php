<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Общее у встроенных массовых действий удаления и восстановления: каждая запись — в своей
 * транзакции через Eloquent (observers и SoftDeletes работают), узел дерева с детьми не удаляется,
 * ошибка одной записи не останавливает остальные.
 */
abstract class DeletesRecords extends Action
{
    protected bool $single = false;

    /**
     * Выполнить операцию над каждой записью и собрать итог.
     *
     * @param  Collection<int, Model>  $records
     * @param  (Closure(Model): (bool|null))  $operation  false — модель отменила операцию
     * @param  string  $done  ключ текста «Удалено: :count»
     * @param  bool  $keepParents  не трогать записи дерева с вложенными
     * @param  bool  $forever  удаление навсегда: вложенные из корзины тоже считаются
     */
    protected function eachRecord(Collection $records, Closure $operation, string $done, bool $keepParents, bool $forever = false): ActionResult
    {
        $count = 0;
        $problems = [];

        foreach ($records as $record) {
            if ($keepParents && $this->resource()->hasChildren($record, $forever)) {
                $problems[] = __('fastleo::resources.tree_has_children');

                continue;
            }

            try {
                DB::connection($record->getConnectionName())->transaction(fn (): null => $operation($record) === false ? throw new OperationCancelled : null);
                $count++;
            } catch (QueryException $exception) {
                $problems[] = DatabaseErrors::explain($exception);
            } catch (OperationCancelled) {
                $problems[] = __('fastleo::resources.delete_cancelled');
            }
        }

        $message = __($done, ['count' => $count], Fastleo::LOCALE);

        if ($problems === []) {
            return ActionResult::success($message);
        }

        $failed = __('fastleo::actions.failed', ['count' => count($problems), 'reason' => implode(' ', array_unique($problems))], Fastleo::LOCALE);

        return $count > 0 ? ActionResult::success($message.' '.$failed) : ActionResult::error($failed);
    }
}
