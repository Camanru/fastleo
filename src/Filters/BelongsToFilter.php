<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Support\ContainsExpression;
use Fastleo\Support\RecordKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Фильтр по связанной записи: варианты и подписи — из одноимённого поля `BelongsTo` ресурса
 * (с его `query()` и `titleColumn()`, без учёта `dependsOn()`). Если записей больше
 * `fastleo.relations.preload`, список ищет по серверу.
 *
 * ```php
 * BelongsToFilter::make('category_id', 'Рубрика');
 * ```
 */
class BelongsToFilter extends Filter
{
    /**
     * Ключ записи (формат ключа связанной модели) или null.
     */
    #[Override]
    public function value(mixed $input): ?string
    {
        $value = self::string($input);

        return $value !== null && RecordKey::valid($this->relation()->relatedModel(), $value) ? $value : null;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::filters.belongs-to';
    }

    /**
     * Поле связи с тем же именем.
     *
     * @throws LogicException в ресурсе нет поля BelongsTo с именем фильтра
     */
    public function relation(): BelongsTo|BelongsToMany
    {
        $field = $this->field();

        return $field instanceof BelongsTo ? $field : throw new LogicException(__('fastleo::exceptions.filter_belongs_to', ['filter' => $this->name], Fastleo::LOCALE));
    }

    /**
     * Записей больше, чем выводится в странице: список ищет по серверу.
     */
    public function isRemote(): bool
    {
        return $this->relation()->baseOptionsQuery()->count() > $this->preloadLimit();
    }

    /**
     * Варианты для страницы (не больше `fastleo.relations.preload`) или найденные по подписи.
     *
     * @return list<array{value: string, label: string}>
     */
    public function searchOptions(string $search = '', ?int $limit = null): array
    {
        $field = $this->relation();
        $query = $field->baseOptionsQuery();
        $title = $query->qualifyColumn($field->getTitleColumn());

        if ($search !== '') {
            ContainsExpression::where($query, $title, $search);
        }

        $records = $query->orderBy($title)->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit ?? $this->preloadLimit())->get();

        return array_values($records->map(fn (Model $record): array => $this->option($record))->all());
    }

    /**
     * Выбранный вариант (подпись — ключ, если запись не видна).
     *
     * @return list<array{value: string, label: string}>
     */
    public function selected(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $record = $this->relation()->baseOptionsQuery()->whereKey($value)->first();

        return [$record instanceof Model ? $this->option($record) : ['value' => $value, 'label' => $value]];
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        $query->where($query->qualifyColumn($this->name), $value);
    }

    /**
     * Запись → вариант списка.
     *
     * @return array{value: string, label: string}
     */
    private function option(Model $record): array
    {
        $key = $record->getKey();

        return ['value' => is_scalar($key) ? (string) $key : '', 'label' => $this->relation()->titleOf($record)];
    }

    /**
     * Сколько вариантов выводится сразу в странице.
     */
    private function preloadLimit(): int
    {
        return max(1, config()->integer('fastleo.relations.preload', 100));
    }
}
