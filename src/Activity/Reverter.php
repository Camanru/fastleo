<?php

declare(strict_types=1);

namespace Fastleo\Activity;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Откат по записи журнала: вернуть прежние значения полей, восстановить удалённую запись.
 *
 * Возвращаются только поля, которые администратор может записывать в разделе сейчас
 * (не readonly, не скрытые, колонки самой модели): откат не обходит описание ресурса.
 * Значения возвращаются как были в базе; правила валидации не повторяются — они прошли при исходном
 * сохранении. Откат — обычное сохранение через Eloquent (observers работают), в журнале он
 * записывается событием `reverted`. Права: `update` для значений, `create` для восстановления из копии,
 * `restore` для записи из корзины.
 */
final readonly class Reverter
{
    /**
     * @param  ActivityRecorder  $recorder  запись журнала
     */
    public function __construct(private ActivityRecorder $recorder) {}

    /**
     * Откатить запись журнала.
     *
     * @throws RevertFailed откат невозможен (текст для администратора)
     */
    public function revert(Activity $activity, Resource $resource): void
    {
        if (! $activity->isRevertable() || $activity->subject_id === null) {
            throw new RevertFailed($this->message('not_revertable'));
        }

        try {
            match (true) {
                in_array($activity->event, ['updated', 'reverted'], true) => $this->restoreValues($activity, $resource, $activity->subject_id),
                $activity->snapshot !== null => $this->recreate($activity, $resource, $activity->subject_id),
                default => $this->restoreFromTrash($resource, $activity->subject_id),
            };
        } catch (QueryException $exception) {
            throw new RevertFailed(DatabaseErrors::explain($exception), 0, $exception);
        } catch (OperationCancelled) {
            throw new RevertFailed($this->message('cancelled'));
        }
    }

    /**
     * Вернуть прежние значения изменённых полей.
     */
    private function restoreValues(Activity $activity, Resource $resource, string $id): void
    {
        $record = $resource->newQuery()->whereKey($id)->first();

        if (! $record instanceof Model) {
            throw new RevertFailed($this->message('missing'));
        }

        Authorization::authorize('update', $resource, $record);

        $writable = [];

        foreach ($resource->writableFields($record) as $field) {
            if ($field->writesAttribute()) {
                $writable[$field->name] = true;
            }
        }

        $old = [];

        foreach ($activity->changes ?? [] as $attribute => $change) {
            if (isset($writable[$attribute]) && array_key_exists('old', $change)) {
                $old[$attribute] = $change['old'];
            }
        }

        if ($old === []) {
            throw new RevertFailed($this->message('nothing'));
        }

        $record->setRawAttributes([...$record->getAttributes(), ...$old]);

        if (! $record->isDirty()) {
            throw new RevertFailed($this->message('same'));
        }

        $this->save(fn (): bool => $record->save(), $record);
    }

    /**
     * Создать удалённую запись заново из копии.
     */
    private function recreate(Activity $activity, Resource $resource, string $id): void
    {
        Authorization::authorize('create', $resource);

        $model = $resource->newModel();

        if ($model->newQuery()->withoutGlobalScopes()->whereKey($id)->exists()) {
            throw new RevertFailed($this->message('exists'));
        }

        // Запись, удалённая из корзины навсегда, возвращается живой, а не снова в корзину.
        $snapshot = $activity->snapshot ?? [];

        if (method_exists($model, 'getDeletedAtColumn')) {
            $column = $model->getDeletedAtColumn();

            if (is_string($column)) {
                unset($snapshot[$column]);
            }
        }

        $model->setRawAttributes($snapshot);
        $model->exists = false;

        // Восстановленная запись должна попадать в выборку раздела (`query()`), иначе админ вернул бы то, что ему не видно.
        $this->save(function () use ($model, $resource, $id): bool {
            if (! $model->save()) {
                return false;
            }

            if (! $resource->newQuery()->whereKey($id)->exists()) {
                throw new RevertFailed($this->message('scope'));
            }

            return true;
        }, $model);
    }

    /**
     * Вернуть запись из корзины.
     */
    private function restoreFromTrash(Resource $resource, string $id): void
    {
        $record = $resource->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($id)->first();

        if (! $record instanceof Model || ! method_exists($record, 'trashed') || ! method_exists($record, 'restore')) {
            throw new RevertFailed($this->message('missing'));
        }

        Authorization::authorize('restore', $resource, $record);

        if (! $record->trashed()) {
            throw new RevertFailed($this->message('same'));
        }

        $this->save(fn (): bool => (bool) $record->restore(), $record);
    }

    /**
     * Выполнить сохранение в транзакции и под названием события `reverted`.
     *
     * @param  callable(): bool  $operation
     */
    private function save(callable $operation, Model $model): void
    {
        $this->recorder->withEvent('reverted', fn () => DB::connection($model->getConnectionName())->transaction(function () use ($operation): void {
            if ($operation() === false) {
                throw new OperationCancelled;
            }
        }));
    }

    /**
     * Текст ошибки отката.
     */
    private function message(string $key): string
    {
        return __('fastleo::activity.revert_'.$key, [], Fastleo::LOCALE);
    }
}
