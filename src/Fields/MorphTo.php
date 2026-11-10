<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\ContainsExpression;
use Fastleo\Support\OptionRule;
use Fastleo\Support\RecordKey;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo as MorphToRelation;
use Illuminate\Database\QueryException;
use LogicException;
use Override;

/**
 * Полиморфная связь «принадлежит»: запись ссылается на запись одного из нескольких разделов
 * (обращение — на статью или товар, комментарий — на запись любого раздела). Колонки `subject_type` и
 * `subject_id` берутся из метода `morphTo()` модели; имя поля — имя этого метода.
 *
 * Типы — только разделы из `types()`: произвольный класс из запроса принять нельзя. Тип записывается как
 * `getMorphClass()` модели раздела (учитывается `Relation::morphMap()`). Выбрать можно запись раздела, который
 * администратору разрешено открывать (`viewAny`), и только из запроса этого раздела (`query()` ресурса плюс
 * сужение из `types()`). Уже сохранённая пара «тип и запись» остаётся, даже если выпала из вариантов.
 *
 * Связь `subject` не читается через Eloquent: класс по строке из базы не создаётся, неизвестный тип показывается текстом.
 *
 * ```php
 * MorphTo::make('subject', 'Объект')->types([
 *     ArticleResource::class,
 *     ProductResource::class => fn (Builder $query) => $query->where('is_visible', true),
 * ]);
 * ```
 */
class MorphTo extends Field implements SearchesOptions
{
    /**
     * Разделы-типы: класс ресурса → сужение вариантов или null.
     *
     * @var array<string, (Closure(Builder<Model>): mixed)|null>
     */
    private array $types = [];

    /** Метод связи в модели, если он называется не так, как поле. */
    private ?string $relationName = null;

    /**
     * Разделы-типы после проверки: адрес раздела → раздел, пустая модель и сужение.
     *
     * @var array<string, array{resource: Resource, model: Model, query: (Closure(Builder<Model>): mixed)|null}>|null
     */
    private ?array $resolved = null;

    /**
     * Загруженные записи: адрес раздела → ключ → запись или null (нет такой, не открыть).
     *
     * @var array<string, array<string, Model|null>>
     */
    private array $records = [];

    /**
     * Имя колонки типа (запоминается: связь строится на пустой модели).
     */
    private ?string $typeColumn = null;

    /**
     * Имя колонки ключа.
     */
    private ?string $idColumn = null;

    /**
     * Разделы, доступные администратору, — права проверяются один раз на поле (поле новое на каждый запрос).
     *
     * @var array<string, array{resource: Resource, model: Model, query: (Closure(Builder<Model>): mixed)|null}>|null
     */
    private ?array $offered = null;

    /**
     * Подпись уже собирается: заголовок записи сам привёл сюда же (цепочка заметок друг на друга).
     */
    private bool $describing = false;

    /**
     * Разделы, записи которых можно выбрать. Класс без сужения — в списке, с сужением — `Класс::class => fn (Builder $q) => …`.
     *
     * ```php
     * MorphTo::make('subject')->types([ArticleResource::class, ProductResource::class => fn ($query) => $query->where('is_visible', true)]);
     * ```
     *
     * @param  array<array-key, class-string<Resource>|(Closure(Builder<Model>): mixed)>  $types
     */
    public function types(array $types): static
    {
        $this->types = [];
        $this->resolved = null;

        foreach ($types as $key => $value) {
            if (is_string($value)) {
                $this->types[$value] = null;
            } elseif (is_string($key)) {
                $this->types[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Метод `morphTo()` модели, если он называется не так, как поле.
     *
     * ```php
     * MorphTo::make('target')->relation('commentable');
     * ```
     */
    public function relation(string $name): static
    {
        $this->relationName = $name;

        return $this;
    }

    /**
     * Метод связи: заданный `relation()` или имя поля.
     */
    public function getRelationName(): string
    {
        return $this->relationName ?? $this->name;
    }

    /**
     * Связь пустой модели ресурса: нужна только для имён колонок. У записи метод не вызывается:
     * `morphTo()` создал бы класс по строке из базы.
     *
     * @return MorphToRelation<Model, Model>
     *
     * @throws LogicException у модели нет метода `morphTo()`
     */
    public function relationInstance(): MorphToRelation
    {
        $model = $this->model();
        $method = $this->getRelationName();
        $relation = method_exists($model, $method) ? $model->{$method}() : null;

        if (! $relation instanceof MorphToRelation) {
            throw new LogicException(__('fastleo::exceptions.relation_missing', [
                'field' => $this->name, 'model' => $model::class, 'method' => $method, 'type' => 'MorphTo',
            ], Fastleo::LOCALE));
        }

        return $relation;
    }

    /**
     * Колонка с типом (`subject_type`).
     */
    public function typeColumn(): string
    {
        return $this->typeColumn ??= $this->relationInstance()->getMorphType();
    }

    /**
     * Колонка с ключом записи (`subject_id`).
     */
    public function idColumn(): string
    {
        return $this->idColumn ??= $this->relationInstance()->getForeignKeyName();
    }

    /**
     * Разделы-типы, как их задал `types()`, после проверки.
     *
     * @return array<string, array{resource: Resource, model: Model, query: (Closure(Builder<Model>): mixed)|null}>
     *
     * @throws LogicException типы не заданы, раздел не найден, две записи одной модели или у поля настройки, которые ему не подходят
     */
    public function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->assertSupported();

        if ($this->types === []) {
            throw new LogicException(__('fastleo::exceptions.morph_types_empty', ['field' => $this->name], Fastleo::LOCALE));
        }

        $resolved = [];
        $models = [];

        foreach ($this->types as $class => $query) {
            $resource = is_a($class, Resource::class, true) ? Fastleo::resources()->find($class::slug()) : null;

            if (! $resource instanceof $class) {
                throw new LogicException(__('fastleo::exceptions.morph_type_unknown', ['field' => $this->name, 'class' => $class], Fastleo::LOCALE));
            }

            $model = $resource->newModel();

            if (isset($models[$model::class])) {
                throw new LogicException(__('fastleo::exceptions.morph_type_duplicate', ['field' => $this->name, 'model' => $model::class], Fastleo::LOCALE));
            }

            $models[$model::class] = true;
            $resolved[$resource::slug()] = ['resource' => $resource, 'model' => $model, 'query' => $query];
        }

        return $this->resolved = $resolved;
    }

    /**
     * Разделы-типы, записи которых администратор может выбрать: ему разрешено открывать список раздела.
     *
     * @return array<string, array{resource: Resource, model: Model, query: (Closure(Builder<Model>): mixed)|null}>
     */
    public function offered(): array
    {
        return $this->offered ??= array_filter($this->resolved(), fn (array $type): bool => Authorization::allows('viewAny', $type['resource']));
    }

    /**
     * Тип можно выбрать и искать в нём записи.
     */
    public function allowsType(string $slug): bool
    {
        return isset($this->offered()[$slug]);
    }

    /**
     * Типы для списка в форме.
     *
     * @return list<array{value: string, label: string}>
     */
    public function offeredTypes(): array
    {
        $options = [];

        foreach ($this->offered() as $slug => $type) {
            $options[] = ['value' => $slug, 'label' => $type['resource']::singular()];
        }

        return $options;
    }

    /**
     * Сохранённая пара записи: класс из колонки типа и ключ; null — поле не заполнено.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function stored(Model $record): ?array
    {
        $class = $record->getAttribute($this->typeColumn());
        $key = $record->getAttribute($this->idColumn());

        if (! is_string($class) || $class === '' || ! is_scalar($key) || $key === '') {
            return null;
        }

        return [$class, (string) $key];
    }

    /**
     * Адрес раздела для класса из колонки типа; null — такого типа в `types()` нет.
     * Узнаются и `getMorphClass()` модели, и её имя класса (записи, сохранённые до `morphMap`).
     */
    protected function slugOf(string $class): ?string
    {
        foreach ($this->resolved() as $slug => $type) {
            if ($class === $type['model']::class || $class === $this->morphClassOf($type['model'])) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Значение для колонки типа (`getMorphClass()`); null — сайт требует карту типов (`enforceMorphMap()`), а модели в ней нет.
     */
    private function morphClassOf(Model $model): ?string
    {
        try {
            return $model->getMorphClass();
        } catch (ClassMorphViolationException) {
            return null;
        }
    }

    /**
     * Сохранённая пара есть, но выбрать её в форме нельзя: тип убран из `types()`, раздел закрыт или класса больше нет.
     * Такое значение форма показывает текстом, не отправляет и не меняет.
     */
    public function isLocked(?Model $record): bool
    {
        if (! $record instanceof Model || ! $record->exists) {
            return false;
        }

        $stored = $this->stored($record);

        return $stored !== null && ! $this->allowsType($this->slugOf($stored[0]) ?? '');
    }

    /**
     * Текст сохранённого значения, которое выбрать нельзя (`Класс #5`).
     */
    public function lockedText(Model $record): string
    {
        $stored = $this->stored($record);

        return $stored === null ? '' : $this->describe($this->slugOf($stored[0]), $stored[0], $stored[1], null);
    }

    /**
     * Значение формы: адрес раздела и ключ записи; неизвестный тип и раздел, закрытый администратору, — `type: null`
     * и сохранённый класс в `morph_class` (адрес закрытого раздела не раскрывается).
     *
     * @return array{type: string|null, id: string|null, morph_class?: string}
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        $stored = $this->stored($record);

        if ($stored === null) {
            return ['type' => null, 'id' => null];
        }

        $slug = $this->slugOf($stored[0]);

        return $slug === null || ! $this->allowsType($slug) ? ['type' => null, 'id' => $stored[1], 'morph_class' => $stored[0]] : ['type' => $slug, 'id' => $stored[1]];
    }

    /**
     * Пара из запроса или файла: строки без пробелов по краям; чего-то не хватает или не строка — null.
     *
     * @return array{type: string|null, id: string|null}
     */
    public function selection(mixed $value): array
    {
        $part = function (string $key) use ($value): ?string {
            $item = is_array($value) ? ($value[$key] ?? null) : null;
            $item = is_int($item) ? (string) $item : $item;

            return is_string($item) && trim($item) !== '' ? trim($item) : null;
        };

        return ['type' => $part('type'), 'id' => $part('id')];
    }

    /**
     * Значение формы: `['type' => адрес раздела, 'id' => ключ]`; у новой записи обе части пусты.
     */
    #[Override]
    public function formValue(?Model $record): mixed
    {
        return $record instanceof Model && $record->exists ? $this->hydrate($record) : ['type' => null, 'id' => null];
    }

    /**
     * Проверенное значение → пара без пустых частей: нет типа или записи — очищаются обе колонки.
     *
     * Эхо сохранённого значения с неизвестным типом (`type: null`, `id`, `morph_class` — так его отдаёт MCP; проверка
     * пропускает только неизменённое) остаётся как есть: `echo: true`.
     *
     * @return array{type: string|null, id: string|null, echo?: true}
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $pair = $this->selection($value);

        if (is_array($value) && ($value['echo'] ?? false) === true || ($pair['type'] === null && $pair['id'] !== null && is_array($value) && is_string($value['morph_class'] ?? null))) {
            return ['type' => null, 'id' => null, 'echo' => true];
        }

        return $pair['type'] === null || $pair['id'] === null ? ['type' => null, 'id' => null] : $pair;
    }

    /**
     * Записать колонки типа и записи (с учётом `$fillable` модели).
     *
     * @throws LogicException колонки нет среди `$fillable` модели
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        foreach ([$this->typeColumn(), $this->idColumn()] as $column) {
            if (! $record->isFillable($column)) {
                throw new LogicException(__('fastleo::exceptions.field_not_fillable', [
                    'field' => $column, 'resource' => $this->name, 'model' => $record::class,
                ], Fastleo::LOCALE));
            }
        }

        $pair = $this->dehydrate($value);
        $type = $pair['type'] === null ? null : ($this->resolved()[$pair['type']] ?? null);

        if (isset($pair['echo']) || ($record->exists && $pair['type'] !== null && $this->hydrate($record) === $pair)) {
            return; // пара не менялась: колонки не переписываются
        }

        if ($type === null || $pair['id'] === null) {
            $record->fill([$this->typeColumn() => null, $this->idColumn() => null]);

            return;
        }

        $record->fill([
            $this->typeColumn() => $type['model']->getMorphClass(),
            $this->idColumn() => $type['model']->getKeyType() === 'int' ? (int) $pair['id'] : $pair['id'],
        ]);
    }

    /**
     * Поле пишет в две колонки сразу (`fill()`), а не в атрибут с именем поля.
     */
    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    /**
     * Правила: массив только из `type` и `id`, обе части вместе, раздел из `types()` и запись из его вариантов.
     * Сохранённая пара записи проходит, только если не изменились обе части.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $locked = $this->isLocked($record);
        $rules[$this->name] = array_map(
            fn (mixed $rule): mixed => match (true) {
                $rule === 'required' && $locked => 'nullable',
                $rule instanceof OptionRule && (string) $rule === 'morph-complete' => $this->completeRule($record),
                $rule instanceof OptionRule => $this->existsRule($record),
                default => $rule,
            },
            $rules[$this->name],
        );

        return $rules;
    }

    /**
     * Правила самого значения: массив из `type` и `id`, обе части вместе, запись из вариантов.
     *
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['array:type,id,morph_class', $this->completeRule(null), $this->existsRule(null)];
    }

    /**
     * Оба значения заданы или оба пусты; пустое поле допустимо, если оно не обязательное.
     */
    private function completeRule(?Model $record): OptionRule
    {
        return new OptionRule(function (mixed $value) use ($record): bool {
            $pair = $this->selection($value);

            if ($record instanceof Model && $record->exists && $pair['type'] === null && $pair['id'] !== null) {
                return is_array($value) && $this->hydrate($record) === ['type' => null, 'id' => $pair['id'], 'morph_class' => $value['morph_class'] ?? null];
            }

            return $pair['type'] === null && $pair['id'] === null ? ! $this->required : $pair['type'] !== null && $pair['id'] !== null;
        }, 'required', 'morph-complete');
    }

    /**
     * Раздел входит в типы и доступен администратору, запись есть в его вариантах (или пара не менялась).
     */
    private function existsRule(?Model $record): OptionRule
    {
        return new OptionRule(function (mixed $value) use ($record): bool {
            $pair = $this->selection($value);

            if ($pair['type'] === null || $pair['id'] === null) {
                return true;
            }

            if ($record instanceof Model && $record->exists && $this->hydrate($record) === ['type' => $pair['type'], 'id' => $pair['id']]) {
                return true;
            }

            return $this->exists($pair['type'], $pair['id']);
        }, 'exists', 'morph');
    }

    /**
     * Запись есть среди вариантов раздела-типа. Ошибка базы (ключ не того вида) — «нет такой записи».
     */
    private function exists(string $slug, string $id): bool
    {
        $type = $this->offered()[$slug] ?? null;

        if ($type === null || RecordKey::strict($type['model'], $id) === false) {
            return false;
        }

        try {
            return $this->typeQuery($type)->whereKey($id)->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Запрос записей раздела-типа: `query()` ресурса и сужение из `types()`.
     *
     * @param  array{resource: Resource, model: Model, query: (Closure(Builder<Model>): mixed)|null}  $type
     * @return Builder<Model>
     */
    private function typeQuery(array $type): Builder
    {
        $query = $type['resource']->newQuery();

        if ($type['query'] instanceof Closure) {
            ($type['query'])($query);
        }

        return $query;
    }

    /**
     * Варианты записей выбранного раздела (`$dependency` — его адрес): по подписи, без выбранного раздела или в закрытом — пусто.
     *
     * @return list<array{value: string, label: string}>
     */
    public function searchOptions(string $search, int $limit = 20, mixed $dependency = null): array
    {
        $type = is_string($dependency) ? ($this->offered()[$dependency] ?? null) : null;

        if ($type === null) {
            return [];
        }

        $query = $this->typeQuery($type);
        $column = $this->searchColumn($type['model']);

        if ($search !== '') {
            ContainsExpression::where($query, $query->qualifyColumn($column), $search);
        }

        $options = [];

        foreach ($query->orderBy($query->qualifyColumn($column))->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit)->get() as $record) {
            $options[] = ['value' => $this->keyOf($record), 'label' => $type['resource']->title($record)];
        }

        return $options;
    }

    /**
     * Колонка, по которой ищут запись: `title` или `name` из `$fillable`, иначе ключ.
     */
    private function searchColumn(Model $model): string
    {
        foreach (['title', 'name'] as $column) {
            if (in_array($column, $model->getFillable(), true)) {
                return $column;
            }
        }

        return $model->getKeyName();
    }

    /**
     * Выбранная запись для формы: одна запись с подписью; раздел закрыт — пусто. Записи нет среди вариантов
     * (сохранена раньше) — подпись `#ключ`.
     *
     * @return list<array{value: string, label: string}>
     */
    public function selectedOptions(?string $slug, ?string $id): array
    {
        $type = $slug === null ? null : ($this->offered()[$slug] ?? null);

        if ($slug === null || $id === null || $type === null) {
            return [];
        }

        $record = $this->recordOf($slug, $id);

        return [['value' => $id, 'label' => $record instanceof Model ? $type['resource']->title($record) : '#'.$id]];
    }

    /**
     * Загрузить записи показанной страницы: одна выборка на раздел, а не запрос на строку.
     *
     * @param  iterable<Model>  $records
     */
    #[Override]
    public function prepareFor(iterable $records): void
    {
        $this->records = [];
        $wanted = [];

        foreach ($records as $record) {
            $stored = $this->stored($record);
            $slug = $stored === null ? null : $this->slugOf($stored[0]);

            if ($stored !== null && $slug !== null && isset($this->offered()[$slug]) && RecordKey::strict($this->resolved()[$slug]['model'], $stored[1]) !== false) {
                $wanted[$slug][$stored[1]] = $stored[1];
            }
        }

        foreach ($wanted as $slug => $ids) {
            $found = $this->load($slug, array_values($ids));

            foreach ($ids as $id) {
                $this->records[$slug][$id] = $found[$id] ?? null;
            }
        }
    }

    /**
     * Запись раздела-типа по ключу или null: из загруженных страницей или одним запросом.
     */
    private function recordOf(string $slug, string $id): ?Model
    {
        if (! array_key_exists($id, $this->records[$slug] ?? [])) {
            $type = $this->offered()[$slug] ?? null;
            $this->records[$slug][$id] = $type === null || RecordKey::strict($type['model'], $id) === false ? null : ($this->load($slug, [$id])[$id] ?? null);
        }

        return $this->records[$slug][$id];
    }

    /**
     * Записи раздела по ключам, по ключу записи. Ошибка базы — записей нет.
     *
     * @param  list<string>  $ids
     * @return array<string, Model>
     */
    private function load(string $slug, array $ids): array
    {
        try {
            return $this->typeQuery($this->resolved()[$slug])->whereKey($ids)->get()->keyBy(fn (Model $record): string => $this->keyOf($record))->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Подпись значения: «Статья: Заголовок»; запись не открыть — «Статья #5»; тип неизвестен — «Класс #5».
     */
    private function describe(?string $slug, string $class, string $id, ?Model $record): string
    {
        $type = $slug === null ? null : ($this->resolved()[$slug] ?? null);

        if ($type === null) {
            return basename(str_replace('\\', '/', $class)).' #'.$id;
        }

        if ($this->describing || ! $record instanceof Model) {
            return $type['resource']::singular().' #'.$id;
        }

        $this->describing = true;

        try {
            return $type['resource']::singular().': '.$type['resource']->title($record);
        } finally {
            $this->describing = false;
        }
    }

    /**
     * Подпись связанной записи: название раздела и заголовок; заголовок виден, только если раздел открыт администратору.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof Closure) {
            return ($this->formatUsing)($this->hydrate($record), $record);
        }

        $stored = $this->stored($record);

        if ($stored === null) {
            return null;
        }

        $slug = $this->slugOf($stored[0]);
        $target = $slug === null || ! isset($this->offered()[$slug]) ? null : $this->recordOf($slug, $stored[1]);

        return $this->describe($slug, $stored[0], $stored[1], $target);
    }

    /**
     * Адрес страницы связанной записи в её разделе или null: записи нет, раздел закрыт или просмотр запрещён Policy.
     */
    public function linkUrl(Model $record): ?string
    {
        $target = $this->linkTarget($record);

        return $target === null ? null : $target[0]->viewUrl($target[1]);
    }

    /**
     * Адрес той же записи для окна просмотра (`frame=1`) или null: раздел показывает записи не в окне либо ссылки нет.
     */
    public function linkModalUrl(Model $record): ?string
    {
        $target = $this->linkTarget($record);
        $url = $target === null ? null : $target[0]->viewUrl($target[1]);

        return $target === null ? null : $target[0]->viewModalUrl($url);
    }

    /**
     * Раздел и запись, на которые указывает связь, или null.
     *
     * @return array{0: Resource, 1: Model}|null
     */
    private function linkTarget(Model $record): ?array
    {
        $stored = $this->stored($record);
        $slug = $stored === null ? null : $this->slugOf($stored[0]);
        $target = $stored === null || $slug === null || ! isset($this->offered()[$slug]) ? null : $this->recordOf($slug, $stored[1]);

        return $target instanceof Model ? [$this->resolved()[$slug]['resource'], $target] : null;
    }

    /**
     * Ячейка файла: `адрес-раздела:ключ` (`articles:5`); неизвестный тип и пустая пара — пустая ячейка.
     */
    #[Override]
    public function exportValue(Model $record): string|int|float|null
    {
        $value = $this->hydrate($record);

        return $value['type'] === null || $value['id'] === null ? null : $value['type'].':'.$value['id'];
    }

    /**
     * Ячейка файла → пара: делится по первому двоеточию (ключ может содержать `:`).
     *
     * @return array{type: string|null, id: string|null}
     */
    #[Override]
    public function importValue(mixed $cell): array
    {
        $text = self::cellString($cell);

        if ($text === null) {
            return ['type' => null, 'id' => null];
        }

        [$type, $id] = array_pad(explode(':', $text, 2), 2, null);

        return $this->selection(['type' => $type, 'id' => $id]);
    }

    /**
     * Значение — пара из двух колонок, а не одно: управлять `visibleWhen()` такое поле не может.
     */
    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Поле не работает с сортировкой, поиском, правкой в ячейке, зависимостями, переводами и значением вместо пустого.
     *
     * @throws LogicException поле настроено так, как для него нельзя
     */
    private function assertSupported(): void
    {
        $unsupported = array_keys(array_filter([
            'sortable()' => $this->sortable,
            'searchable()' => $this->searchable,
            'inlineEditable()' => $this->inlineEditable,
            'dependsOn()' => $this->dependsOn !== null,
            'translatable()' => $this->translatable,
            'emptyValue()' => $this->emptyValue !== null,
            'fromQuery()' => $this->fromQuery,
        ]));

        if ($unsupported !== []) {
            throw new LogicException(__('fastleo::exceptions.morph_unsupported', ['field' => $this->name, 'methods' => implode(', ', $unsupported)], Fastleo::LOCALE));
        }
    }

    /**
     * Ключ записи строкой.
     */
    private function keyOf(Model $record): string
    {
        $key = $record->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
