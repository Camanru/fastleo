<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Fields\Password;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordKey;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ResourceQuery;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Список, форма, просмотр, сохранение и удаление записей любого ресурса.
 *
 * Каждое действие: ресурс по адресу (нет — 404) → запись через `query()` ресурса (нет — 404)
 * → право по Policy (нет — 403) → работа только с полями ресурса.
 */
final class ResourceController
{
    /**
     * Куда перейти после сохранения: кнопки «Сохранить», «Сохранить и выйти», «Сохранить и создать ещё».
     */
    private const array AFTER_SAVE = ['edit', 'index', 'create'];

    /**
     * Список записей: поиск, сортировка, постранично, корзина.
     */
    public function index(Request $request, string $resource): View
    {
        $resource = $this->resource($resource);
        Authorization::authorize('viewAny', $resource);

        $trashed = $resource::usesSoftDeletes() && $request->query('trashed') === '1';
        $list = new ResourceQuery($resource, $request, $trashed);

        return view('fastleo::resources.index', [
            'resource' => $resource,
            'list' => $list,
            'records' => $list->paginate(),
            'trashed' => $trashed,
            'branch' => $list->parent === null ? null : $resource->newQuery()->whereKey($list->parent)->first(),
        ]);
    }

    /**
     * Форма создания записи.
     */
    public function create(Request $request, string $resource): View
    {
        $resource = $this->resource($resource);
        Authorization::authorize('create', $resource);

        // «+» в дереве: родитель новой записи подставляется из ?parent=, если такая запись видна в разделе.
        $defaults = [];
        $tree = $resource::treeColumn();
        $parent = $request->query('parent');

        if ($tree !== null && is_string($parent) && RecordKey::valid($resource->newModel(), $parent) && $resource->newQuery()->whereKey($parent)->exists()) {
            $defaults[$tree] = $parent;
        }

        return view('fastleo::resources.form', ['resource' => $resource, 'record' => null, 'defaults' => $defaults]);
    }

    /**
     * Сохранить новую запись.
     */
    public function store(Request $request, string $resource): RedirectResponse
    {
        $resource = $this->resource($resource);
        Authorization::authorize('create', $resource);

        $record = $resource->newModel();

        return $this->save($request, $resource, $record, null);
    }

    /**
     * Страница просмотра записи.
     */
    public function show(string $resource, string $id): View
    {
        $resource = $this->resource($resource);
        $record = $this->record($resource, $id, $resource::usesSoftDeletes());
        Authorization::authorize('view', $resource, $record);

        return view('fastleo::resources.show', ['resource' => $resource, 'record' => $record]);
    }

    /**
     * Форма редактирования записи.
     */
    public function edit(string $resource, string $id): View
    {
        $resource = $this->resource($resource);
        $record = $this->record($resource, $id);
        Authorization::authorize('update', $resource, $record);

        return view('fastleo::resources.form', ['resource' => $resource, 'record' => $record]);
    }

    /**
     * Сохранить изменения записи.
     */
    public function update(Request $request, string $resource, string $id): RedirectResponse
    {
        $resource = $this->resource($resource);
        $record = $this->record($resource, $id);
        Authorization::authorize('update', $resource, $record);

        return $this->save($request, $resource, $record, $record);
    }

    /**
     * Удалить запись: в корзину для моделей с SoftDeletes, иначе навсегда.
     */
    public function destroy(string $resource, string $id): RedirectResponse
    {
        $resource = $this->resource($resource);
        $record = $this->record($resource, $id);
        Authorization::authorize('delete', $resource, $record);

        if ($resource->hasChildren($record)) {
            return back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', __('fastleo::resources.tree_has_children'));
        }

        try {
            DB::connection($record->getConnectionName())->transaction(fn (): null => $record->delete() === false ? throw new OperationCancelled : null);
        } catch (QueryException $exception) {
            return back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', DatabaseErrors::explain($exception));
        } catch (OperationCancelled) {
            return back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', __('fastleo::resources.delete_cancelled'));
        }

        return to_route('fastleo.resources.index', $resource::slug())
            ->with('fastleo.success', __($resource::usesSoftDeletes() ? 'fastleo::resources.trashed_message' : 'fastleo::resources.deleted'));
    }

    /**
     * Вернуть запись из корзины.
     */
    public function restore(string $resource, string $id): RedirectResponse
    {
        $resource = $this->resource($resource);
        abort_unless($resource::usesSoftDeletes(), 404);
        $record = $this->record($resource, $id, true);
        abort_unless($this->isTrashed($record), 404);
        Authorization::authorize('restore', $resource, $record);

        $this->restoreRecord($record);
        RecordWriter::moveToEnd($resource, $record);

        return to_route('fastleo.resources.index', ['resource' => $resource::slug(), 'trashed' => 1])
            ->with('fastleo.success', __('fastleo::resources.restored'));
    }

    /**
     * Удалить запись из корзины навсегда.
     */
    public function forceDelete(string $resource, string $id): RedirectResponse
    {
        $resource = $this->resource($resource);
        abort_unless($resource::usesSoftDeletes(), 404);
        $record = $this->record($resource, $id, true);
        abort_unless($this->isTrashed($record), 404);
        Authorization::authorize('forceDelete', $resource, $record);

        if ($resource->hasChildren($record, true)) {
            return back(fallback: route('fastleo.resources.index', ['resource' => $resource::slug(), 'trashed' => 1]))->with('fastleo.error', __('fastleo::resources.tree_has_children'));
        }

        try {
            DB::connection($record->getConnectionName())->transaction(fn (): null => $record->forceDelete() === false ? throw new OperationCancelled : null);
        } catch (QueryException $exception) {
            return back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', DatabaseErrors::explain($exception));
        } catch (OperationCancelled) {
            return back(fallback: route('fastleo.resources.index', ['resource' => $resource::slug(), 'trashed' => 1]))->with('fastleo.error', __('fastleo::resources.delete_cancelled'));
        }

        return to_route('fastleo.resources.index', ['resource' => $resource::slug(), 'trashed' => 1])
            ->with('fastleo.success', __('fastleo::resources.deleted'));
    }

    /**
     * Проверить форму и записать поля ресурса в модель в одной транзакции.
     *
     * В модель попадают только видимые и не «только для чтения» поля ресурса, через `fill()` модели;
     * поле вне `$fillable` — ошибка разработчика (ресурс разошёлся с моделью). Остальное из запроса
     * игнорируется. Ошибка БД, которую не поймала валидация, показывается понятным текстом над формой.
     */
    private function save(Request $request, Resource $resource, Model $record, ?Model $existing): RedirectResponse
    {
        // После ошибки — всегда обратно на форму, а не на «предыдущий адрес» из Referer.
        $formUrl = $existing instanceof Model
            ? route('fastleo.resources.edit', [$resource::slug(), $existing->getKey()])
            : route('fastleo.resources.create', $resource::slug());

        // Сервер получил форму не целиком (лимит PHP max_input_vars): без отказа хвост вложенных записей удалился бы.
        if ($request->has('_fl_begin') && ! $request->has('_fl_end')) {
            return redirect($formUrl)->with('fastleo.error', __('fastleo::resources.form_truncated'));
        }

        $fields = $resource->writableFields($existing);
        $validator = validator(
            RecordWriter::controllingInput($resource, $existing, $request->all()),
            RecordWriter::rules($resource, $existing, $fields),
            ValidationMessages::all(),
            $resource->validationAttributes($existing),
        );

        if ($validator->fails()) {
            // Пароли (в том числе поля с нестандартным именем) не возвращаются в форму через сессию.
            throw new ValidationException($validator, redirect($formUrl)->withInput($request->except($this->secretInputs($resource)))->withErrors($validator));
        }

        try {
            RecordWriter::write($resource, $record, $fields, RecordWriter::inInputOrder($validator->validated(), $request->all()));
        } catch (QueryException $exception) {
            return redirect($formUrl)->withInput($request->except($this->secretInputs($resource)))->with('fastleo.error', DatabaseErrors::explain($exception));
        } catch (OperationCancelled) {
            return redirect($formUrl)->withInput($request->except($this->secretInputs($resource)))->with('fastleo.error', __('fastleo::resources.save_cancelled'));
        }

        $after = $request->input('_after');
        $after = is_string($after) && in_array($after, self::AFTER_SAVE, true) ? $after : 'edit';

        if ($after === 'create' && ! Authorization::allows('create', $resource)) {
            $after = 'index';
        }

        $redirect = match ($after) {
            'index' => to_route('fastleo.resources.index', $resource::slug()),
            'create' => to_route('fastleo.resources.create', $resource::slug()),
            default => Authorization::allows('update', $resource, $record)
                ? to_route('fastleo.resources.edit', [$resource::slug(), $record->getKey()])
                : to_route('fastleo.resources.index', $resource::slug()),
        };

        return $redirect->with('fastleo.success', __('fastleo::resources.saved'));
    }

    /**
     * Ресурс по адресу раздела или 404.
     */
    /**
     * Ручной порядок: `POST /fastleo/{resource}/reorder` с `id` и `direction=up|down` (стрелки)
     * или `before`/`after` = ключ соседа (перетаскивание). Двигается только среди соседей
     * (тот же родитель в дереве, записи в рамках `query()` ресурса); соседи перенумеровываются 1..n.
     */
    public function reorder(Request $request, string $resource): JsonResponse|RedirectResponse
    {
        $resource = $this->resource($resource);
        $column = $resource::sortColumn();
        abort_if($column === null, 404);

        $id = $request->input('id');
        abort_unless(is_string($id) || is_int($id), 404);
        $record = $this->record($resource, (string) $id);
        abort_unless(Authorization::allowsReorder($resource, $record), 403);

        $siblings = $this->siblings($resource, $record)->keyBy(fn (Model $sibling): string => $this->key($sibling));
        $keys = array_map(strval(...), $siblings->keys()->all());
        $key = $this->key($record);
        $current = (int) array_search($key, $keys, true);
        array_splice($keys, $current, 1);

        $target = match (true) {
            $request->input('direction') === 'up' => max(0, $current - 1),
            $request->input('direction') === 'down' => min(count($keys), $current + 1),
            is_scalar($request->input('before')) => array_search((string) $request->input('before'), $keys, true),
            is_scalar($request->input('after')) => ($position = array_search((string) $request->input('after'), $keys, true)) === false ? false : $position + 1,
            default => false,
        };
        abort_if($target === false, 422);
        array_splice($keys, $target, 0, [$key]);

        try {
            DB::connection($record->getConnectionName())->transaction(function () use ($keys, $siblings, $column): void {
                foreach ($keys as $index => $siblingKey) {
                    $sibling = $siblings->get($siblingKey);

                    if ($sibling instanceof Model) {
                        $sibling->setAttribute($column, $index + 1);

                        if ($sibling->isDirty($column)) {
                            $sibling->save();
                        }
                    }
                }
            });
        } catch (QueryException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => DatabaseErrors::explain($exception)], 422)
                : back(fallback: route('fastleo.resources.index', $resource::slug()))->with('fastleo.error', DatabaseErrors::explain($exception));
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back(fallback: route('fastleo.resources.index', $resource::slug()));
    }

    /**
     * Имена полей паролей (и их повторов): не возвращаются в форму после ошибки проверки или базы.
     *
     * @return list<string>
     */
    private function secretInputs(Resource $resource): array
    {
        $names = [];

        foreach ($resource->schema()->fields() as $field) {
            if ($field instanceof Password) {
                $names[] = $field->name;
                $names[] = $field->name.'_confirmation';
            }
        }

        return $names;
    }

    /**
     * Соседи записи (вместе с ней) в текущем порядке.
     *
     * @return Collection<int, Model>
     */
    private function siblings(Resource $resource, Model $record): Collection
    {
        $query = RecordWriter::siblingsQuery($resource, $record, $resource->newQuery());
        $model = $query->getModel();

        return $query->orderBy($model->qualifyColumn((string) $resource::sortColumn()))->orderBy($model->getQualifiedKeyName())->get();
    }

    /**
     * Ключ записи строкой.
     */
    private function key(Model $record): string
    {
        $key = $record->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    private function resource(string $slug): Resource
    {
        $resource = Fastleo::resources()->find($slug);
        abort_unless($resource instanceof Resource, 404);

        return $resource;
    }

    /**
     * Запись раздела по ключу или 404. Ищется через `query()` ресурса: чужие записи не открываются.
     * Числовой ключ — только цифры (до 18), строковый (UUID/ULID) — латиница, цифры и дефис.
     */
    private function record(Resource $resource, string $id, bool $withTrashed = false): Model
    {
        $model = $resource->newModel();
        abort_unless(RecordKey::valid($model, $id), 404);

        $query = $resource->newQuery();

        if ($withTrashed) {
            $query = $this->withTrashed($query);
        }

        $record = $query->whereKey($id)->first();
        abort_unless($record instanceof Model, 404);

        return $record;
    }

    /**
     * Запрос вместе с записями из корзины.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function withTrashed(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SoftDeletingScope::class);
    }

    /**
     * Запись лежит в корзине.
     */
    private function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed() === true;
    }

    /**
     * Вернуть запись из корзины.
     */
    private function restoreRecord(Model $record): void
    {
        if (method_exists($record, 'restore')) {
            $record->restore();
        }
    }
}
