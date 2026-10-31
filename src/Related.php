<?php

declare(strict_types=1);

namespace Fastleo;

use Closure;
use Fastleo\Fields\Field;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Nested;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelation;
use InvalidArgumentException;
use LogicException;

/**
 * Связанная таблица на странице записи: записи другого раздела, у которых внешний ключ ведёт на эту запись
 * (машины и заявки клиента). Показывается под формой на просмотре и правке; колонки, сортировка, поиск, действия
 * и права берутся из дочернего раздела, «Добавить» открывает его форму с подставленным родителем.
 *
 * Описывается в `relations()` ресурса; имя — метод связи `HasMany` модели:
 *
 * ```php
 * public function relations(): array
 * {
 *     return [
 *         Related::make('cars', CarResource::class),
 *         Related::make('offers', OfferResource::class)->label('Заявки')->perPage(10),
 *     ];
 * }
 * ```
 *
 * Ту же связь нельзя одновременно показывать полем `HasMany` в форме правки: форма перезапишет то, что
 * изменили в таблице. Поле можно оставить только в форме создания: `HasMany::make(...)->hideOnEdit()`.
 */
final class Related
{
    /**
     * Связь родителя, привязанная к ресурсу (после `bind()`).
     *
     * @var HasManyRelation<Model, Model>|null
     */
    private ?HasManyRelation $relation = null;

    /** Дочерний раздел (после `bind()`). */
    private ?Resource $child = null;

    private bool $expandable = false;

    private bool $aside = false;

    /** @var list<string> */
    private array $quickAdd = [];

    private ?string $addLabel = null;

    /** @var list<string> */
    private array $columns = [];

    /**
     * @param  class-string<Resource>  $resource  дочерний раздел
     */
    private function __construct(public readonly string $name, public readonly string $resource, private ?string $label = null, private ?int $perPage = null)
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $name], Fastleo::LOCALE));
        }
    }

    /**
     * Связанная таблица.
     *
     * ```php
     * Related::make('offers', OfferResource::class);
     * ```
     *
     * @param  string  $relation  метод связи `HasMany` модели родителя
     * @param  class-string<Resource>  $resource  дочерний раздел
     */
    public static function make(string $relation, string $resource): self
    {
        return new self($relation, $resource);
    }

    /**
     * Заголовок таблицы (по умолчанию — название дочернего раздела).
     *
     * ```php
     * Related::make('offers', OfferResource::class)->label('Заявки клиента');
     * ```
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Быстрое добавление: под таблицей — маленькая форма с этими полями дочернего раздела; запись создаётся без перехода
     * на другую страницу, внешний ключ ставится сам. Остальные обязательные поля дочернего раздела должны иметь значение по
     * умолчанию (иначе `fastleo:doctor` предупредит). Поля — простые, без `dependsOn()`, `visibleWhen()` и переводов.
     *
     * ```php
     * Related::make('tasks', TaskResource::class)->quickAdd(['title', 'due_at', 'assignee_id']);
     * ```
     *
     * @param  list<string>  $fields  имена полей дочернего раздела
     */
    public function quickAdd(array $fields): self
    {
        $this->quickAdd = array_values(array_unique($fields));

        return $this;
    }

    /**
     * Столбцы таблицы: только эти поля дочернего раздела и в этом порядке (по умолчанию — все столбцы его списка). Нужно
     * для тесной боковой колонки (`aside()`); столбец связи с родителем в таблице не показывается в любом случае.
     *
     * ```php
     * Related::make('tasks', TaskResource::class)->aside()->columns(['title', 'due_at']);
     * ```
     *
     * @param  list<string>  $fields  имена полей дочернего раздела, выводимых в его списке
     */
    public function columns(array $fields): self
    {
        $this->columns = array_values(array_unique($fields));

        return $this;
    }

    /**
     * Столбцы таблицы для текущего администратора: поля из `columns()` или все столбцы списка дочернего раздела, без столбца
     * связи с родителем.
     *
     * @return list<Field>
     */
    public function tableFields(): array
    {
        $listed = [];

        foreach ($this->child()->listFields() as $field) {
            if ($field->name !== $this->foreignKey()) {
                $listed[$field->name] = $field;
            }
        }

        if ($this->columns === []) {
            return array_values($listed);
        }

        return array_values(array_filter(array_map(fn (string $name): ?Field => $listed[$name] ?? null, $this->columns)));
    }

    /**
     * Подпись кнопки «Добавить» (по умолчанию — «Добавить»), например «Добавить задачу».
     */
    public function addLabel(string $label): self
    {
        $this->addLabel = $label;

        return $this;
    }

    /**
     * Подпись кнопки «Добавить».
     */
    public function getAddLabel(): string
    {
        return $this->addLabel ?? __('fastleo::resources.related_add');
    }

    /**
     * Поля быстрого добавления в том порядке, в каком они описаны, — поля формы создания дочернего раздела.
     *
     * @return list<Field>
     */
    public function quickAddFields(): array
    {
        if ($this->quickAdd === []) {
            return [];
        }

        $writable = [];

        foreach ($this->child()->writableFields(null) as $field) {
            $writable[$field->name] = $field;
        }

        return array_values(array_filter(array_map(fn (string $name): ?Field => $writable[$name] ?? null, $this->quickAdd)));
    }

    /**
     * Есть форма быстрого добавления.
     */
    public function hasQuickAdd(): bool
    {
        return $this->quickAdd !== [];
    }

    /**
     * Таблица — в боковой колонке карточки (справа от формы), а не под ней: небольшие списки рядом с формой —
     * задачи, комментарии, вложения.
     *
     * ```php
     * Related::make('tasks', TaskResource::class)->aside();
     * ```
     */
    public function aside(bool $aside = true): self
    {
        $this->aside = $aside;

        return $this;
    }

    /**
     * Таблица в боковой колонке.
     */
    public function isAside(): bool
    {
        return $this->aside;
    }

    /**
     * Строки таблицы раскрываются: стрелка в начале строки показывает под ней связанные таблицы дочернего раздела
     * (его `relations()`), и так вглубь — заявка → позиции → варианты, не покидая страницы родителя.
     *
     * ```php
     * Related::make('orders', OrderResource::class)->expandable(); // у OrderResource есть свой relations()
     * ```
     */
    public function expandable(bool $expandable = true): self
    {
        $this->expandable = $expandable;

        return $this;
    }

    /**
     * Строки раскрываются.
     */
    public function isExpandable(): bool
    {
        return $this->expandable;
    }

    /**
     * Сколько записей показывать на странице таблицы (по умолчанию — как в списке дочернего раздела), от 1 до 100.
     *
     * ```php
     * Related::make('offers', OfferResource::class)->perPage(10);
     * ```
     */
    public function perPage(int $perPage): self
    {
        $this->perPage = max(1, min($perPage, 100));

        return $this;
    }

    /**
     * Привязать к ресурсу-родителю: проверить связь и дочерний раздел.
     *
     * @internal вызывает `Resource::listRelations()`
     *
     * @throws LogicException связь не `HasMany`, раздел не найден или работает с другой моделью, связь выведена и полем формы
     */
    public function bind(Resource $parent): self
    {
        $model = $parent->newModel();
        $relation = method_exists($model, $this->name) ? $model->{$this->name}() : null;

        if (! $relation instanceof HasManyRelation) {
            throw new LogicException(__('fastleo::exceptions.relation_missing', [
                'field' => $this->name, 'model' => $model::class, 'method' => $this->name, 'type' => 'HasMany',
            ], Fastleo::LOCALE));
        }

        $child = is_subclass_of($this->resource, Resource::class) ? Fastleo::resources()->find($this->resource::slug()) : null;

        if (! $child instanceof $this->resource) {
            throw new LogicException(__('fastleo::exceptions.related_resource', ['relation' => $this->name, 'class' => $this->resource], Fastleo::LOCALE));
        }

        if (! $relation->getRelated() instanceof ($child::model())) {
            throw new LogicException(__('fastleo::exceptions.related_model', [
                'relation' => $this->name, 'model' => $relation->getRelated()::class, 'class' => $this->resource, 'other' => $child::model(),
            ], Fastleo::LOCALE));
        }

        foreach ($parent->schema()->fields() as $field) {
            if ($field instanceof HasMany && $field->name === $this->name && $field->showsInEditForm()) {
                throw new LogicException(__('fastleo::exceptions.related_conflict', ['relation' => $this->name, 'resource' => $parent::class], Fastleo::LOCALE));
            }
        }

        $this->child = $child;
        $this->relation = $relation;
        $this->assertColumns();
        $this->assertQuickAdd();

        return $this;
    }

    /**
     * Заголовок таблицы.
     */
    public function getLabel(): string
    {
        return $this->label ?? $this->child()->label();
    }

    /**
     * Записей на странице или null — как в дочернем разделе.
     */
    public function getPerPage(): ?int
    {
        return $this->perPage;
    }

    /**
     * Дочерний раздел.
     */
    public function child(): Resource
    {
        return $this->child ?? throw new LogicException(self::class.'::bind() не вызван.');
    }

    /**
     * Столбец дочерней таблицы с внешним ключом на родителя.
     */
    public function foreignKey(): string
    {
        return $this->relation()->getForeignKeyName();
    }

    /**
     * Ограничение запроса дочернего раздела: записи этого родителя.
     *
     * @return Closure(Builder<Model>): mixed
     */
    public function scopeFor(Model $parent): Closure
    {
        $relation = $this->relation();
        $key = $parent->getAttribute($relation->getLocalKeyName());

        // Ключа у родителя нет (связь по необязательной колонке): записей «без родителя» в таблице родителя быть не должно.
        return fn (Builder $query): Builder => $key === null
            ? $query->whereRaw('1 = 0')
            : $query->where($query->qualifyColumn($relation->getForeignKeyName()), $key);
    }

    /**
     * Значение внешнего ключа для новых записей таблицы: ключ родителя строкой.
     */
    public function foreignValue(Model $parent): string
    {
        $value = $parent->getAttribute($this->relation()->getLocalKeyName());

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Поставить новой дочерней записи внешний ключ этого родителя — так, как это сделала бы связь `HasMany`, независимо от того,
     * есть ли поле ключа в форме дочернего раздела.
     */
    public function attachTo(Model $child, Model $parent): void
    {
        $relation = $this->relation();
        $child->setAttribute($relation->getForeignKeyName(), $parent->getAttribute($relation->getLocalKeyName()));
    }

    /**
     * Связь `HasMany` родителя.
     *
     * @return HasManyRelation<Model, Model>
     */
    private function relation(): HasManyRelation
    {
        return $this->relation ?? throw new LogicException(self::class.'::bind() не вызван.');
    }

    /**
     * Столбцы таблицы должны быть полями дочернего раздела, выводимыми в его списке, и не внешним ключом на родителя.
     * Проверка идёт по описанию раздела, а не по правам администратора.
     *
     * @throws LogicException поля нет в списке дочернего раздела
     */
    private function assertColumns(): void
    {
        $listed = [];

        foreach ($this->child()->schema()->fields() as $field) {
            if ($field->isInList()) {
                $listed[] = $field->name;
            }
        }

        foreach ($this->columns as $name) {
            if (! in_array($name, $listed, true) || $name === $this->foreignKey()) {
                throw new LogicException(__('fastleo::exceptions.related_column', ['relation' => $this->name, 'field' => $name, 'class' => $this->resource], Fastleo::LOCALE));
            }
        }
    }

    /**
     * Поля быстрого добавления должны быть полями формы создания дочернего раздела и простыми.
     *
     * Проверка идёт по описанию раздела, а не по правам текущего администратора: скрытое от него поле просто не попадёт в его форму.
     *
     * @throws LogicException поля нет в форме, оно зависит от других или многоязычное
     */
    private function assertQuickAdd(): void
    {
        $fields = [];

        foreach ($this->child()->schema()->fields() as $field) {
            $fields[$field->name] = $field;
        }

        foreach ($this->quickAdd as $name) {
            $field = $fields[$name] ?? null;

            if (! $field instanceof Field || ! $field->isInForm() || $field instanceof Nested || $field->getDependsOn() !== null || $field->getVisibleWhen() !== null || $field->isTranslatable() || $name === $this->foreignKey()) {
                throw new LogicException(__('fastleo::exceptions.related_quick_field', ['relation' => $this->name, 'field' => $name, 'class' => $this->resource], Fastleo::LOCALE));
            }
        }
    }
}
