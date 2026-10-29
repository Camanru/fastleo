<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\QueriesRelatedRecords;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Support\OptionRule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as BelongsToRelation;
use Illuminate\Support\Str;
use LogicException;
use Override;

/**
 * Связь «принадлежит»: запись ссылается на запись другой таблицы (`category_id` → категория).
 * Имя поля — колонка внешнего ключа; связь берётся из метода модели (`category()` для `category_id`).
 * Список вариантов с поиском; если вариантов больше `fastleo.relations.preload`, поиск идёт по серверу.
 *
 * ```php
 * BelongsTo::make('category_id', 'Категория')->required()->sortable();
 * BelongsTo::make('clinic_id', 'Клиника')->titleColumn('short_name')->query(fn (Builder $q) => $q->where('is_active', true));
 * ```
 */
class BelongsTo extends Field implements SearchesOptions
{
    use QueriesRelatedRecords;

    /**
     * Связь модели (запоминается).
     *
     * @var BelongsToRelation<Model, Model>|null
     */
    private ?BelongsToRelation $relationInstance = null;

    /**
     * Метод связи: заданный `relation()` или имя поля без `_id` (`category_id` → `category`).
     */
    #[Override]
    public function getRelationName(): string
    {
        return $this->relationName ?? Str::camel(Str::beforeLast($this->name, '_id'));
    }

    /**
     * Связь `BelongsTo` модели ресурса.
     *
     * @return BelongsToRelation<Model, Model>
     *
     * @throws LogicException у модели нет такого метода связи или внешний ключ связи — другая колонка
     */
    public function relationInstance(): BelongsToRelation
    {
        if ($this->relationInstance instanceof BelongsToRelation) {
            return $this->relationInstance;
        }

        $model = $this->model();
        $method = $this->getRelationName();
        $relation = method_exists($model, $method) ? $model->{$method}() : null;

        if (! $relation instanceof BelongsToRelation) {
            throw new LogicException(__('fastleo::exceptions.relation_missing', [
                'field' => $this->name, 'model' => $model::class, 'method' => $method, 'type' => 'BelongsTo',
            ], Fastleo::LOCALE));
        }

        if ($relation->getForeignKeyName() !== $this->name) {
            throw new LogicException(__('fastleo::exceptions.relation_foreign_key', [
                'field' => $this->name, 'method' => $method, 'key' => $relation->getForeignKeyName(),
            ], Fastleo::LOCALE));
        }

        return $this->relationInstance = $relation;
    }

    #[Override]
    public function relatedModel(): Model
    {
        return $this->relationInstance()->getRelated();
    }

    /**
     * Связь подгружается в список одним запросом.
     *
     * @return list<string>
     */
    #[Override]
    public function eagerLoad(): array
    {
        return [$this->getRelationName()];
    }

    #[Override]
    public function hydrate(Model $record): ?string
    {
        $value = $record->getAttribute($this->name);

        return is_scalar($value) ? (string) $value : null;
    }

    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = parent::dehydrate($value);

        return is_numeric($value) && $this->relatedModel()->getKeyType() === 'int' ? (int) $value : $value;
    }

    /**
     * Правила; сохранённое значение записи проходит проверку, даже если связанная запись скрыта.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $rules[$this->name] = array_map(fn (mixed $rule): mixed => $rule instanceof OptionRule ? $this->optionRules($record)[1] : $rule, $rules[$this->name]);

        return $rules;
    }

    #[Override]
    protected function typeRules(): array
    {
        return $this->optionRules();
    }

    /**
     * Подпись связанной записи; запись не найдена — значение ключа как есть.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof \Closure) {
            return parent::display($record);
        }

        $related = $record->getRelationValue($this->getRelationName());

        return $related instanceof Model ? $this->titleOf($related) : $this->format($record->getAttribute($this->name));
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.combobox';
    }

    /**
     * Список выбирает одну запись.
     */
    #[Override]
    public function isMultiple(): bool
    {
        return false;
    }
}
