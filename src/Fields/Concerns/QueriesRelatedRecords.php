<?php

declare(strict_types=1);

namespace Fastleo\Fields\Concerns;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Support\ContainsExpression;
use Fastleo\Support\OptionRule;
use Fastleo\Support\RecordKey;
use Fastleo\Support\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Общее у полей-связей с выбором записей (BelongsTo, BelongsToMany): запрос вариантов,
 * подписи, поиск по серверу и правило «значение есть среди вариантов».
 *
 * Если для связанной модели есть ресурс-дерево (`$treeColumn`), варианты в странице идут в порядке дерева с отступами.
 *
 * Варианты — записи связанной модели с её глобальными скоупами (удалённые в корзину не видны)
 * и ограничением `query()` поля. Ни выбрать, ни подставить запросом запись вне этого запроса нельзя.
 */
trait QueriesRelatedRecords
{
    /** Метод связи в модели. */
    protected ?string $relationName = null;

    /** Колонка связанной модели с подписью записи. */
    protected ?string $titleColumn = null;

    /**
     * Ограничение вариантов.
     *
     * @var (Closure(Builder<Model>): mixed)|null
     */
    protected ?Closure $queryUsing = null;

    /**
     * Ограничение вариантов по значению поля-зависимости.
     *
     * @var (Closure(Builder<Model>, mixed): mixed)|null
     */
    protected ?Closure $dependsUsing = null;

    /**
     * Сколько вариантов (по значению зависимости, запоминается на запрос).
     *
     * @var array<string, int>
     */
    private array $optionsCount = [];

    /**
     * Метод связи в модели, если он называется не так, как поле.
     *
     * ```php
     * BelongsTo::make('author_id')->relation('writer');
     * ```
     */
    public function relation(string $name): static
    {
        $this->relationName = $name;

        return $this;
    }

    /**
     * Колонка связанной модели с подписью записи (по умолчанию `title` или `name`, если они в `$fillable`).
     *
     * ```php
     * BelongsTo::make('clinic_id')->titleColumn('short_name');
     * ```
     *
     * @throws InvalidArgumentException имя не похоже на имя колонки
     */
    public function titleColumn(string $column): static
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $column) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $column], Fastleo::LOCALE));
        }

        $this->titleColumn = $column;

        return $this;
    }

    /**
     * Ограничить варианты: замыкание получает запрос связанной модели.
     *
     * ```php
     * BelongsTo::make('clinic_id')->query(fn (Builder $query) => $query->where('is_active', true));
     * ```
     *
     * @param  Closure(Builder<Model>): mixed  $query
     */
    public function query(Closure $query): static
    {
        $this->queryUsing = $query;
        $this->optionsCount = [];

        return $this;
    }

    /**
     * Зависимый список: варианты ограничиваются значением другого поля формы. Замыкание получает
     * запрос вариантов и значение поля (null, если не выбрано). При смене поля список обновляется.
     *
     * ```php
     * BelongsTo::make('city_id', 'Город')->dependsOn('region_id', fn (Builder $query, mixed $region) => $query->where('region_id', $region));
     * ```
     *
     * @param  Closure(Builder<Model>, mixed): mixed  $query
     *
     * @throws InvalidArgumentException имя поля не похоже на имя колонки
     */
    public function dependsOn(string $field, Closure $query): static
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $field) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $field], Fastleo::LOCALE));
        }

        $this->dependsOn = $field;
        $this->dependsUsing = $query;
        $this->optionsCount = [];

        return $this;
    }

    /**
     * Метод связи в модели.
     */
    abstract public function getRelationName(): string;

    /**
     * Пустой экземпляр связанной модели.
     */
    abstract public function relatedModel(): Model;

    /**
     * Колонка подписи записи.
     */
    public function getTitleColumn(): string
    {
        if ($this->titleColumn !== null) {
            return $this->titleColumn;
        }

        $related = $this->relatedModel();

        foreach (['title', 'name'] as $column) {
            if (in_array($column, $related->getFillable(), true)) {
                return $this->titleColumn = $column;
            }
        }

        return $this->titleColumn = $related->getKeyName();
    }

    /**
     * Запрос вариантов: `baseOptionsQuery()` + ограничение по значению зависимости (`dependsOn()`).
     *
     * @return Builder<Model>
     */
    public function optionsQuery(mixed $dependency = null): Builder
    {
        $query = $this->baseOptionsQuery();

        if ($this->dependsUsing instanceof Closure) {
            ($this->dependsUsing)($query, Field::dependencyValue($dependency));
        }

        return $query;
    }

    /**
     * Запрос вариантов без учёта зависимости: связанная модель со скоупами и `query()` поля.
     *
     * @return Builder<Model>
     */
    public function baseOptionsQuery(): Builder
    {
        $query = $this->relatedModel()->newQuery();

        if ($this->queryUsing instanceof Closure) {
            ($this->queryUsing)($query);
        }

        return $query;
    }

    /**
     * Вариантов больше, чем выводится в странице (`fastleo.relations.preload`): список ищет по серверу.
     */
    public function isRemote(mixed $dependency = null): bool
    {
        $key = Field::conditionValue($dependency);
        $this->optionsCount[$key] ??= $this->optionsQuery($dependency)->count();

        return $this->optionsCount[$key] > self::preloadLimit();
    }

    /**
     * Все варианты для страницы (если их не больше `fastleo.relations.preload`).
     *
     * @return list<array{value: string, label: string}>
     */
    public function preloadedOptions(mixed $dependency = null): array
    {
        $resource = Fastleo::resources()->treeFor($this->relatedModel()::class);
        $column = $resource?->treeColumn();

        if (! $resource instanceof \Fastleo\Resource || $column === null) {
            return $this->options($this->orderedOptionsQuery($dependency)->limit(self::preloadLimit())->get());
        }

        // Модель-дерево (есть ресурс с $treeColumn): варианты в порядке дерева, вложенность — отступом «— ».
        $tree = Tree::load($this->optionsQuery($dependency), $column, $resource::sortColumn());
        $keys = array_slice($tree->order(), 0, self::preloadLimit());
        $records = $keys === [] ? collect() : $this->optionsQuery($dependency)->whereKey($keys)->get()->keyBy(fn (Model $record): string => self::key($record));
        $options = [];

        foreach ($keys as $key) {
            $record = $records->get($key);

            if ($record instanceof Model) {
                $options[] = ['value' => $key, 'label' => str_repeat('— ', $tree->depth($key)).$this->titleOf($record)];
            }
        }

        return $options;
    }

    /**
     * Поиск вариантов по подписи (для списка с поиском по серверу).
     *
     * @return list<array{value: string, label: string}>
     */
    public function searchOptions(string $search, int $limit = 20, mixed $dependency = null): array
    {
        $query = $this->orderedOptionsQuery($dependency);

        if ($search !== '') {
            $query->whereRaw(new ContainsExpression($query->qualifyColumn($this->getTitleColumn())), [ContainsExpression::pattern($search)]);
        }

        return $this->options($query->limit($limit)->get());
    }

    /**
     * Сохранённые ключи связи записи: проходят проверку и остаются выбранными в форме, даже если
     * сейчас вне вариантов (связанная запись в корзине, скрыта `query()` или не подходит под зависимость).
     *
     * @return list<string>
     */
    public function savedKeys(?Model $record): array
    {
        if (! $record instanceof Model || ! $record->exists) {
            return [];
        }

        $value = $this->isMultiple() ? $this->hydrate($record) : $record->getOriginal($this->name);

        return array_values(array_map(strval(...), array_filter(is_array($value) ? $value : [$value], fn (mixed $key): bool => is_scalar($key) && $key !== '' && ! $this->isEmptyValue($key))));
    }

    /**
     * Подписи выбранных записей (только среди допустимых вариантов). Ключи из `$kept`, которых
     * среди вариантов нет (сохранённая связь), остаются выбранными: с подписью, если запись видна
     * без зависимости, иначе с подписью-ключом. В списке нескольких записей скрытая запись (корзина,
     * `query()`) не показывается — её связь и так не трогается при сохранении.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $kept
     * @return list<array{value: string, label: string}>
     */
    public function optionsFor(array $keys, mixed $dependency = null, array $kept = []): array
    {
        if ($keys === []) {
            return [];
        }

        $records = $this->optionsQuery($dependency)->whereKey($keys)->get()->keyBy(fn (Model $record): string => self::key($record));
        $missing = array_values(array_intersect(array_diff($keys, array_map(strval(...), $records->keys()->all())), $kept));
        $base = $missing === [] ? collect() : $this->baseOptionsQuery()->whereKey($missing)->get()->keyBy(fn (Model $record): string => self::key($record));

        $options = [];

        foreach ($keys as $key) {
            $record = $records->get($key);

            $record ??= $base->get($key);

            if ($record instanceof Model) {
                $options[] = $this->option($record);
            } elseif (in_array($key, $kept, true) && ! $this->isMultiple()) {
                $options[] = ['value' => $key, 'label' => $key];
            }
        }

        return $options;
    }

    /**
     * Подпись связанной записи.
     */
    public function titleOf(Model $record): string
    {
        $title = $record->getAttribute($this->getTitleColumn());

        return is_scalar($title) || $title instanceof \Stringable ? (string) $title : self::key($record);
    }

    /**
     * Правило: значение — ключ записи из запроса вариантов. Для числовых ключей перед ним — `integer`.
     *
     * @return list<mixed>
     */
    protected function optionRules(?Model $record = null): array
    {
        // Уже сохранённое значение записи принимается, даже если оно сейчас вне вариантов
        // (связанная запись в корзине или скрыта query()): иначе правка формы стёрла бы связь.
        $saved = $this->savedKeys($record);
        $numeric = $this->relatedModel()->getKeyType() === 'int';
        // Правило `integer` не останавливает следующие (без `bail`): не-число в запросе к числовому
        // ключу на PostgreSQL — ошибка базы (500), поэтому формат проверяется здесь же.
        $exists = new OptionRule(
            fn (mixed $value, array $data): bool => is_scalar($value) && (
                (! $this->required && $this->isEmptyValue($value))
                || (in_array((string) $value, $saved, true) && $this->dependencyUnchanged($record, $data))
                || (RecordKey::strict($this->relatedModel(), (string) $value) !== false && $this->optionExists($value, $data))
            ),
            'exists',
        );

        return $numeric ? ['integer', $exists] : ['string', $exists];
    }

    /**
     * Значение есть среди вариантов поля. Ошибка базы (значение зависимости не того типа, например строка для
     * числовой колонки на PostgreSQL) — «нет такого варианта», а не 500.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function optionExists(mixed $value, array $data): bool
    {
        try {
            return $this->optionsQuery($this->dependencyFrom($data))->whereKey($value)->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Варианты в порядке подписи.
     *
     * @return Builder<Model>
     */
    protected function orderedOptionsQuery(mixed $dependency = null): Builder
    {
        $query = $this->optionsQuery($dependency);

        return $query->orderBy($query->qualifyColumn($this->getTitleColumn()))->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * Записи → варианты списка.
     *
     * @param  iterable<Model>  $records
     * @return list<array{value: string, label: string}>
     */
    protected function options(iterable $records): array
    {
        $options = [];

        foreach ($records as $record) {
            $options[] = $this->option($record);
        }

        return $options;
    }

    /**
     * Запись → вариант списка.
     *
     * @return array{value: string, label: string}
     */
    protected function option(Model $record): array
    {
        return ['value' => self::key($record), 'label' => $this->titleOf($record)];
    }

    /**
     * Ключ записи строкой.
     */
    protected static function key(Model $record): string
    {
        $key = $record->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Сколько вариантов выводится сразу в странице.
     */
    private static function preloadLimit(): int
    {
        return max(1, config()->integer('fastleo.relations.preload', 100));
    }
}
