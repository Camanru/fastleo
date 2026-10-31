<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Выборка для списка раздела по параметрам адреса: поиск `q`, фильтры `filter[имя]`,
 * сортировка `sort`/`direction`, «все записи» `per_page=all`, число записей `per_page=50`, корзина, ветка дерева `parent`.
 *
 * Сортировать можно только по полям `sortable()`, искать — только по `searchable()`:
 * имена колонок из адреса в запрос не попадают; фильтры — только описанные в `filters()` ресурса.
 * Без поиска, фильтров, сортировки и вне корзины раздел
 * с `$treeColumn` показывается деревом, с `$sortColumn` — в ручном порядке.
 */
final class ResourceQuery
{
    /** Строка поиска (обрезанная) или пустая строка. */
    public readonly string $search;

    /** Поле сортировки из адреса или null — порядок по умолчанию. */
    public readonly ?string $sort;

    /** Направление сортировки. */
    public readonly string $direction;

    /** Показать все записи одной страницей (не больше `fastleo.list.max_all`). */
    public readonly bool $all;

    /** Число записей на странице, выбранное в списке (`per_page=50`, только из `Resource::perPageOptions()`), или null. */
    public readonly ?int $size;

    /** Ветка дерева: ключ записи, чьи потомки показываются, или null — всё дерево. */
    public readonly ?string $parent;

    /**
     * Проверенные значения включённых фильтров по имени.
     *
     * @var array<string, mixed>
     */
    public readonly array $filters;

    /**
     * Значения фильтров по умолчанию (`Filter::default()`): вид списка без параметров в адресе.
     *
     * @var array<string, mixed>
     */
    private readonly array $defaults;

    /** Дерево записей раздела (строится один раз на запрос). */
    private ?Tree $tree = null;

    /**
     * @param  (Closure(Builder<Model>): mixed)|null  $scope  своё ограничение запроса (связанная таблица: записи одного родителя); с ним список плоский — без дерева
     */
    public function __construct(private readonly Resource $resource, Request $request, public readonly bool $trashed = false, bool $withDefaults = true, private readonly ?Closure $scope = null)
    {
        $search = $request->query('q');
        $this->search = is_string($search) ? mb_substr(trim($search), 0, 255) : '';

        $sort = $request->query('sort');
        $sortable = array_map(fn (Field $field): string => $field->name, array_filter($resource->listFields(), fn (Field $field): bool => $field->isSortable()));
        $this->sort = is_string($sort) && in_array($sort, $sortable, true) ? $sort : null;
        $this->direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $this->all = $request->query('per_page') === 'all';
        $size = $request->query('per_page');
        $this->size = is_string($size) && ctype_digit($size) && in_array((int) $size, $resource::perPageOptions(), true) ? (int) $size : null;

        $input = $request->query('filter');
        $filters = [];
        $defaults = [];

        foreach ($resource->listFilters() as $name => $filter) {
            // Ключ есть в адресе (даже пустой — «Все») — решает администратор; нет — умолчание фильтра.
            $given = is_array($input) && array_key_exists($name, $input);
            $default = $withDefaults ? $filter->value($filter->defaultInput()) : null;
            $value = $given ? $filter->value($input[$name]) : $default;

            if ($value !== null) {
                $filters[$name] = $value;
            }

            if ($default !== null) {
                $defaults[$name] = $default;
            }
        }

        $this->filters = $filters;
        $this->defaults = $defaults;

        $parent = $request->query('parent');
        $this->parent = is_string($parent) && RecordKey::valid($this->resource->newModel(), $parent) && $this->isTree() && $this->tree()->has($parent) ? $parent : null;
    }

    /**
     * Список показывается деревом: у ресурса `$treeColumn`, нет поиска и сортировки по столбцу, не корзина.
     */
    public function isTree(): bool
    {
        return ! $this->scope instanceof Closure && $this->resource::treeColumn() !== null && $this->isDefaultView();
    }

    /**
     * Записи идут в ручном порядке `$sortColumn` — можно двигать стрелками и перетаскиванием.
     */
    public function isManualOrder(): bool
    {
        return $this->resource::sortColumn() !== null && $this->isDefaultView();
    }

    /**
     * Дерево записей раздела (с ограничением `query()` ресурса).
     */
    public function tree(): Tree
    {
        $column = $this->resource::treeColumn() ?? $this->resource->newModel()->getKeyName();

        return $this->tree ??= Tree::load($this->resource->newQuery(), $column, $this->resource::sortColumn());
    }

    /**
     * Записи текущей страницы.
     *
     * @return LengthAwarePaginatorContract<int, Model>
     */
    public function paginate(?int $perPage = null): LengthAwarePaginatorContract
    {
        $perPage = $this->all ? max(1, config()->integer('fastleo.list.max_all', 1000)) : ($this->size ?? $perPage ?? $this->resource::perPage());

        if (! $this->isTree()) {
            return $this->query()->paginate($perPage)->withQueryString();
        }

        // Дерево: страница — отрезок порядка обхода; записи отрезка загружаются одним запросом.
        $order = $this->tree()->order($this->parent);
        $page = Paginator::resolveCurrentPage();
        $keys = array_slice($order, ($page - 1) * $perPage, $perPage);
        $position = array_flip($keys);
        $records = $keys === [] ? new Collection : $this->query()->whereKey($keys)->get()
            ->sortBy(fn (Model $record): int => $position[$this->key($record)] ?? 0)
            ->values();

        return new LengthAwarePaginator($records, count($order), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ])->withQueryString();
    }

    /**
     * Запрос с ограничением ресурса, корзиной, поиском и сортировкой.
     *
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        $query = $this->resource->newQuery();

        $model = $query->getModel();

        if ($this->trashed && method_exists($model, 'getQualifiedDeletedAtColumn')) {
            $column = $model->getQualifiedDeletedAtColumn();
            $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull(is_string($column) ? $column : 'deleted_at');
        }

        if ($this->scope instanceof Closure) {
            ($this->scope)($query);
        }

        $this->applySearch($query);

        foreach ($this->filters as $name => $value) {
            $resourceFilter = $this->resource->listFilters()[$name];
            $query->where(fn (Builder $where) => $resourceFilter->apply($where, $value));
        }

        $this->applyOrder($query);

        // Подписи связей в списке — одним запросом на связь, а не на каждую строку.
        $relations = array_merge(...array_map(fn (Field $field): array => $field->eagerLoad(), $this->resource->listFields()));

        return $relations === [] ? $query : $query->with(array_values(array_unique($relations)));
    }

    /**
     * Есть ли поля для поиска.
     */
    public function hasSearch(): bool
    {
        return $this->searchableFields() !== [];
    }

    /**
     * Есть ли фильтры у раздела.
     */
    public function hasFilters(): bool
    {
        return $this->resource->listFilters() !== [];
    }

    /**
     * Список сужен поиском или фильтром.
     */
    public function isNarrowed(): bool
    {
        return $this->search !== '' || $this->filters !== [];
    }

    /**
     * Вид списка отличается от того, что открывается без параметров: есть поиск или фильтры не такие, как по умолчанию
     * (в том числе выбрано «Все» при умолчании). От него зависит кнопка «Сбросить».
     */
    public function isChanged(): bool
    {
        return $this->search !== '' || $this->filters !== $this->defaults;
    }

    /**
     * Поиск подстроки без учёта регистра по полям `searchable()`; `%` и `_` ищутся как обычные символы.
     *
     * @param  Builder<Model>  $query
     */
    private function applySearch(Builder $query): void
    {
        $fields = $this->searchableFields();

        if ($this->search === '' || $fields === []) {
            return;
        }

        $search = $this->search;

        $query->where(function (Builder $where) use ($fields, $search): void {
            foreach ($fields as $field) {
                $column = $where->qualifyColumn($field->name);

                // Многоязычное поле — поиск по каждому языку (JSON-путь колонки).
                foreach ($field->isTranslatable() ? array_map(fn (string $locale): string => $column.'->'.$locale, Fastleo::locales()) : [$column] as $target) {
                    ContainsExpression::where($where, $target, $search, 'or');
                }
            }
        });
    }

    /**
     * Сортировка: выбранная в списке, иначе `$defaultOrder` ресурса, иначе новые сверху.
     * Последним всегда идёт ключ — порядок страниц стабильный.
     *
     * @param  Builder<Model>  $query
     */
    private function applyOrder(Builder $query): void
    {
        $key = $query->getModel()->getQualifiedKeyName();

        $manual = $this->resource::sortColumn();

        if ($this->sort !== null) {
            // Многоязычное поле сортируется по основному языку.
            $field = $this->resource->schema()->field($this->sort);
            $translatable = $field?->isTranslatable() ?? false;
            // Вычисляемый столбец из query() — псевдоним в запросе, а не колонка таблицы.
            $column = $field?->isFromQuery() === true ? $this->sort : $query->qualifyColumn($this->sort);
            $query->orderBy($column.($translatable ? '->'.Fastleo::locales()[0] : ''), $this->direction);
        } elseif ($manual !== null) {
            $query->orderBy($query->qualifyColumn($manual));
        } elseif (($order = $this->resource::defaultOrder()) !== null) {
            $query->orderBy($query->qualifyColumn($order[0]), $order[1]);
        } else {
            $query->orderByDesc($key);

            return;
        }

        $query->orderBy($key, $this->sort !== null ? $this->direction : 'asc');
    }

    /**
     * Обычный вид списка: без поиска, фильтров, сортировки по столбцу и не корзина.
     */
    private function isDefaultView(): bool
    {
        return $this->sort === null && ! $this->isNarrowed() && ! $this->trashed;
    }

    /**
     * Ключ записи строкой.
     */
    private function key(Model $record): string
    {
        $key = $record->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Поля для поиска.
     *
     * @return list<Field>
     */
    private function searchableFields(): array
    {
        return array_values(array_filter($this->resource->schema()->fields(), fn (Field $field): bool => $field->isSearchable() && ! $field->isHidden()));
    }
}
