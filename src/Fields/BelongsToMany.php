<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\QueriesRelatedRecords;
use Fastleo\Fields\Contracts\SearchesOptions;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as BelongsToManyRelation;
use LogicException;
use Override;

/**
 * Связь «многие ко многим» через промежуточную таблицу: статья ↔ темы, врач ↔ услуги.
 * Имя поля — метод связи модели. Выбор нескольких записей с поиском; сохраняется `sync()` после записи модели.
 * Связи с записями вне вариантов поля (скрытыми `query()` или удалёнными в корзину) не трогаются.
 *
 * ```php
 * BelongsToMany::make('topics', 'Темы');
 * BelongsToMany::make('services', 'Услуги')->query(fn (Builder $q) => $q->where('is_active', true));
 * ```
 */
class BelongsToMany extends Field implements SearchesOptions
{
    use QueriesRelatedRecords;

    /** Длинный список подписей в таблице не нужен. */
    protected bool $showInList = false;

    /**
     * Метод связи — имя поля.
     */
    #[Override]
    public function getRelationName(): string
    {
        return $this->relationName ?? $this->name;
    }

    /**
     * Связь `BelongsToMany` записи (или пустой модели ресурса).
     *
     * @return BelongsToManyRelation<Model, Model>
     *
     * @throws LogicException у модели нет такого метода связи
     */
    public function relationInstance(?Model $record = null): BelongsToManyRelation
    {
        $model = $record ?? $this->model();
        $method = $this->getRelationName();
        $relation = method_exists($model, $method) ? $model->{$method}() : null;

        if (! $relation instanceof BelongsToManyRelation) {
            throw new LogicException(__('fastleo::exceptions.relation_missing', [
                'field' => $this->name, 'model' => $model::class, 'method' => $method, 'type' => 'BelongsToMany',
            ], Fastleo::LOCALE));
        }

        return $relation;
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

    /**
     * Ключи связанных записей.
     *
     * @return list<string>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        $keys = [];

        foreach ($this->relatedRecords($record) as $item) {
            $keys[] = self::key($item);
        }

        return $keys;
    }

    /**
     * Значение по умолчанию — список ключей.
     *
     * @return list<string>
     */
    #[Override]
    protected function formatDefault(mixed $value): array
    {
        return array_values(array_map(strval(...), array_filter(is_array($value) ? $value : [], is_scalar(...))));
    }

    /**
     * Выбранные ключи без пустых и повторов.
     *
     * @return list<int|string>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $int = $this->relatedModel()->getKeyType() === 'int';
        $keys = [];

        foreach (is_array($value) ? $value : [] as $key) {
            if (is_scalar($key) && $key !== '') {
                $keys[(string) $key] = $int ? (int) $key : (string) $key;
            }
        }

        return array_values($keys);
    }

    /**
     * Связь пишется после сохранения записи (`afterSave`), не атрибутом.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        //
    }

    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    /**
     * `sync()` выбранных записей; связи с записями вне вариантов поля остаются как были.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        $relation = $this->relationInstance($record);
        $linked = $this->strings($relation->newPivotQuery()->pluck($relation->getRelatedPivotKeyName())->all());
        $visible = $linked === [] ? [] : $this->strings($this->baseOptionsQuery()->whereKey($linked)->pluck($this->relatedModel()->getKeyName())->all());

        $relation->sync([...array_diff($linked, $visible), ...(is_array($value) ? $value : [])]);
        $record->unsetRelation($this->getRelationName());
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['array'];
    }

    /**
     * Правила: каждый ключ — из вариантов поля или уже сохранённая связь записи.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        return [...parent::getRules($record), $this->name.'.*' => ['distinct', ...$this->optionRules($record)]];
    }

    /**
     * Подписи связанных записей через запятую.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof \Closure) {
            return parent::display($record);
        }

        $titles = [];

        foreach ($this->relatedRecords($record) as $item) {
            $titles[] = $this->titleOf($item);
        }

        return $titles === [] ? null : implode(', ', $titles);
    }

    #[Override]
    protected function packageView(): string
    {
        return 'fastleo::fields.combobox';
    }

    /**
     * Список выбирает несколько записей.
     */
    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Связанные записи (подгруженные или загруженные при обращении).
     *
     * @return list<Model>
     */
    private function relatedRecords(Model $record): array
    {
        $related = $record->getRelationValue($this->getRelationName());
        $records = [];

        foreach (is_iterable($related) ? $related : [] as $item) {
            if ($item instanceof Model) {
                $records[] = $item;
            }
        }

        return $records;
    }

    /**
     * Скалярные значения строками.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * Пустое значение здесь имеет свой смысл (галочка, пароль, файл, список, вложенные записи) — `emptyValue()` недоступен.
     */
    #[Override]
    protected function supportsEmptyValue(): bool
    {
        return false;
    }
}
