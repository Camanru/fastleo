<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Related;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordKey;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ResourceQuery;
use Fastleo\Support\ReturnUrl;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Связанная таблица на странице записи: `GET /fastleo/{resource}/{id}/related/{relation}`.
 *
 * Отдаёт кусок страницы (таблицу, поиск, страницы) для вставки на страницу родителя. Поиск, сортировка и номер
 * страницы живут в адресе этого запроса, поэтому несколько таблиц одной страницы не мешают друг другу. Нужны права
 * `view` родителя и `viewAny` дочернего раздела; записи — только этого родителя в рамках `query()` дочернего раздела.
 */
final class RelatedController
{
    /** Префикс ключа сессии: ввод и ошибки мини-формы действия, не прошедшей проверку в связанной таблице. */
    public const string PENDING_ACTION = 'fastleo.related_action.';

    /**
     * Таблица связанных записей.
     */
    public function __invoke(Request $request, string $resource, string $id, string $relation): View|Response
    {
        $parent = Fastleo::resources()->find($resource);
        abort_unless($parent instanceof Resource, 404);

        $related = $parent->listRelations()[$relation] ?? null;
        abort_unless($related instanceof Related, 404);

        abort_unless(RecordKey::valid($parent->newModel(), $id), 404);
        $record = $parent->newQuery()->whereKey($id)->first();
        abort_unless($record instanceof Model, 404);

        Authorization::authorize('view', $parent, $record);

        $child = $related->child();
        Authorization::authorize('viewAny', $child);

        $list = new ResourceQuery($child, $request, withDefaults: false, scope: $related->scopeFor($record));

        [$openAction, $openIds] = $this->pendingAction($request, $related, $child, is_scalar($record->getKey()) ? (string) $record->getKey() : '');

        $view = view('fastleo::resources.related', [
            'openAction' => $openAction,
            'openIds' => $openIds,
            'resource' => $child,
            'parent' => $parent,
            'parentRecord' => $record,
            'related' => $related,
            'list' => $list,
            'records' => $list->paginate($related->getPerPage()),
            'trashed' => false,
            'returnUrl' => ReturnUrl::from($request) ?? route('fastleo.resources.show', [$parent::slug(), $record->getKey()]),
            'canAdd' => Authorization::allows('update', $parent, $record) && Authorization::allows('create', $child),
        ]);

        if ($openAction === null) {
            return $view;
        }

        // Ввод мини-формы виден форме только в этом запросе: показать страницу и сразу убрать его из сессии.
        $html = $view->render();
        $request->session()->forget('_old_input');

        return new Response($html);
    }

    /**
     * Быстрое добавление (`Related::quickAdd()`): `POST /fastleo/{resource}/{id}/related/{relation}` со значениями
     * в `data[поле]` создаёт дочернюю запись с внешним ключом этого родителя. Нужны права `update` родителя и `create`
     * дочернего раздела — те же, что у кнопки «Добавить». Из ввода берутся только поля быстрого добавления; остальные поля
     * формы создания дочернего раздела получают умолчания, как в обычной форме (`default()`, `Hidden`, `Slug`, `beforeSave()`),
     * и все проходят проверку. Внешний ключ ставит связь родителя. Запись идёт через `RecordWriter`: Eloquent, observers, журнал.
     * Ответ — `{"ok": true}` (201) или `{"message": "…", "errors": {"поле": ["…"]}}` (422).
     */
    public function store(Request $request, string $resource, string $id, string $relation): JsonResponse
    {
        $parent = Fastleo::resources()->find($resource);
        abort_unless($parent instanceof Resource, 404);

        $related = $parent->listRelations()[$relation] ?? null;
        abort_unless($related instanceof Related && $related->hasQuickAdd(), 404);

        abort_unless(RecordKey::valid($parent->newModel(), $id), 404);
        $record = $parent->newQuery()->whereKey($id)->first();
        abort_unless($record instanceof Model, 404);

        $child = $related->child();
        Authorization::authorize('update', $parent, $record);
        Authorization::authorize('create', $child);

        // Пишутся все поля формы создания (умолчания, `Slug`, `Hidden`, `beforeSave()` — как в обычной форме), а из ввода
        // берутся только поля быстрого добавления и внешний ключ родителя.
        $fields = $child->writableFields(null);
        $asked = array_map(fn (Field $field): string => $field->name, $related->quickAddFields());
        $sent = $request->input('data');
        $given = array_intersect_key(is_array($sent) ? $sent : [], array_flip($asked));
        $input = RecordWriter::withDefaults($fields, [...$given, $related->foreignKey() => $related->foreignValue($record)]);
        $validator = validator(
            RecordWriter::controllingInput($child, null, $input),
            RecordWriter::rules($child, null, $fields),
            ValidationMessages::all(),
            $child->validationAttributes(null),
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        try {
            $model = $child->newModel();
            $related->attachTo($model, $record);
            RecordWriter::write($child, $model, $fields, RecordWriter::inInputOrder($validator->validated(), $input));
        } catch (QueryException $exception) {
            return response()->json(['message' => DatabaseErrors::explain($exception), 'errors' => []], 422);
        } catch (OperationCancelled) {
            return response()->json(['message' => __('fastleo::resources.save_cancelled'), 'errors' => []], 422);
        }

        return response()->json(['ok' => true], 201);
    }

    /**
     * Ключ сессии для ввода и ошибок мини-формы действия: связанная таблица одного родителя.
     */
    public static function pendingKey(string $relation, string $parentKey): string
    {
        return self::PENDING_ACTION.$relation.':'.$parentKey;
    }

    /**
     * Мини-форма действия, не прошедшая проверку минуту назад: возвращает ключ действия и записи для открытого окна,
     * а ввод и ошибки делает видимыми форме этого запроса. Забирается один раз.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function pendingAction(Request $request, Related $related, Resource $child, string $parentKey): array
    {
        $pending = $request->session()->pull(self::pendingKey($related->name, $parentKey));

        if (! is_array($pending) || ! is_string($pending['action'] ?? null) || ! is_int($pending['at'] ?? null) || $pending['at'] < now()->getTimestamp() - 60 || ! array_key_exists($pending['action'], $child->listActions())) {
            return [null, []];
        }

        $request->session()->flashInput(is_array($pending['input'] ?? null) ? $pending['input'] : []);
        $errors = new MessageBag;

        foreach (is_array($pending['errors'] ?? null) ? $pending['errors'] : [] as $key => $messages) {
            foreach ((array) $messages as $message) {
                if (is_string($key) && is_string($message)) {
                    $errors->add($key, $message);
                }
            }
        }

        view()->share('errors', new ViewErrorBag()->put('default', $errors));

        return [$pending['action'], array_values(array_filter((array) ($pending['ids'] ?? []), is_string(...)))];
    }
}
