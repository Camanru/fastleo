<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Actions\Action;
use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\ActionInput;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запуск действия над одной или несколькими записями раздела.
 *
 * Записи ищутся через `query()` ресурса (чужие не попадают), у каждой проверяется способность
 * действия по Policy: записи без права пропускаются, если права нет ни на одну — 403.
 */
final class ActionsController
{
    /**
     * `POST /fastleo/{resource}/actions/{action}` с `ids[]` (ключи записей) и `data[...]` (мини-форма).
     */
    public function __invoke(Request $request, string $resource, string $action): Response
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);
        Authorization::authorize('viewAny', $resource);

        $action = $resource->listActions()[$action] ?? null;
        abort_unless($action instanceof Action, 404);

        $ids = ActionInput::ids($resource, $request->input('ids'));

        if ($ids === null) {
            $max = max(1, config()->integer('fastleo.list.max_all', 1000));

            return back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', __('fastleo::actions.invalid_selection', ['max' => $max]));
        }

        abort_unless($action->isBulk() || count($ids) === 1, 404);

        $query = $resource->newQuery();

        if ($action->isForTrash()) {
            $model = $query->getModel();
            $column = method_exists($model, 'getQualifiedDeletedAtColumn') ? $model->getQualifiedDeletedAtColumn() : null;
            abort_unless(is_string($column), 404);
            $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull($column);
        }

        $records = $query->whereKey($ids)->get();
        $allowed = $records->filter(fn (Model $record): bool => $action->allows($record))->values();
        abort_if($allowed->isEmpty(), $records->isEmpty() ? 404 : 403);

        $data = $this->data($request, $action);

        try {
            $result = $action->handle($allowed, $data);
        } catch (QueryException $exception) {
            return back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', DatabaseErrors::explain($exception));
        }

        if ($result->response instanceof Response) {
            return $result->response;
        }

        $message = $result->message;
        $skipped = $records->count() - $allowed->count();

        if ($skipped > 0) {
            $message = trim(($message ?? '').' '.__('fastleo::actions.skipped', ['count' => $skipped]));
        }

        $redirect = $result->redirect !== null ? redirect($result->redirect) : back(fallback: route('fastleo.resources.index', $resource::slug()));

        return $message === null ? $redirect : $redirect->with('fastleo.'.$result->type, $message);
    }

    /**
     * Проверенные значения мини-формы действия. Ошибка — назад с окном формы открытым (в связанной таблице — через сессию).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function data(Request $request, Action $action): array
    {
        $validator = ActionInput::validator($action, $request->all());

        if ($validator->fails()) {
            $input = $request->except(['data.password', 'data.password_confirmation', 'data.current_password']);
            $relation = $request->input('_related');
            $parent = $request->input('_related_parent', '');

            // Форма из связанной таблицы: таблица подгружается отдельным запросом, поэтому ввод и ошибки ждут её в сессии —
            // под ключом «связь + родитель», чтобы их взяла таблица этого родителя, а не соседняя с тем же именем связи.
            if (is_string($relation) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $relation) === 1 && is_string($parent) && preg_match('/^[A-Za-z0-9_-]{0,64}$/', $parent) === 1) {
                session()->put(RelatedController::pendingKey($relation, $parent), [
                    'action' => $action->key(),
                    'ids' => array_values(array_filter((array) $request->input('ids', []), is_string(...))),
                    'input' => $input,
                    'errors' => $validator->errors()->getMessages(),
                    'at' => now()->getTimestamp(),
                ]);

                throw new ValidationException($validator, back());
            }

            session()->flash('fastleo.action', $action->key());

            // Поле с именем «password» другого типа тоже не должно попасть в сессию.
            throw new ValidationException($validator, back()->withInput($input)->withErrors($validator));
        }

        return ActionInput::values($action, $validator);
    }
}
