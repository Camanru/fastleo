<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Support\OperationCancelled;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelation;
use InvalidArgumentException;
use Override;

/**
 * Список вложенных записей в форме родителя: добавить, удалить, перетащить.
 * Имя поля — метод связи `HasMany` модели. Убранный из формы блок удаляется (`delete()` модели блока).
 *
 * ```php
 * HasMany::make('prices', 'Цены')
 *     ->fields([Text::make('title', 'Услуга')->required(), Number::make('price', 'Цена')])
 *     ->orderColumn('sort')
 *     ->max(50);
 * ```
 */
class HasMany extends Nested
{
    /** Колонка порядка блоков. */
    protected ?string $orderColumn = null;

    /** Наименьшее число блоков. */
    protected ?int $minBlocks = null;

    /** Наибольшее число блоков (по умолчанию 200; меняется методом `max()`). */
    protected ?int $maxBlocks = 200;

    /**
     * Колонка порядка: блоки идут и сохраняются в порядке формы (1, 2, 3…), их можно перетаскивать.
     *
     * ```php
     * HasMany::make('prices')->orderColumn('sort');
     * ```
     *
     * @throws InvalidArgumentException имя не похоже на имя колонки
     */
    public function orderColumn(string $column): static
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $column) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $column], Fastleo::LOCALE));
        }

        $this->orderColumn = $column;

        return $this;
    }

    /**
     * Не меньше стольких блоков.
     *
     * ```php
     * HasMany::make('prices')->min(1);
     * ```
     */
    public function min(int $blocks): static
    {
        $this->minBlocks = max(0, $blocks);

        return $this;
    }

    /**
     * Не больше стольких блоков.
     *
     * ```php
     * HasMany::make('prices')->max(20);
     * ```
     */
    public function max(int $blocks): static
    {
        $this->maxBlocks = max(1, $blocks);

        return $this;
    }

    /**
     * Колонка порядка или null.
     */
    public function getOrderColumn(): ?string
    {
        return $this->orderColumn;
    }

    /**
     * Наименьшее число блоков или null.
     */
    public function getMin(): ?int
    {
        return $this->minBlocks;
    }

    /**
     * Наибольшее число блоков или null.
     */
    public function getMax(): ?int
    {
        return $this->maxBlocks;
    }

    #[Override]
    public function isSingle(): bool
    {
        return false;
    }

    #[Override]
    protected function matchesRelation(mixed $relation): bool
    {
        return $relation instanceof HasManyRelation;
    }

    /**
     * Блоки в порядке колонки `orderColumn()` (затем по ключу).
     *
     * @return Collection<int, Model>
     */
    #[Override]
    public function children(Model $record): Collection
    {
        $children = parent::children($record);
        $column = $this->orderColumn;

        return $column === null ? $children : $children->sortBy(fn (Model $child): array => [$child->getAttribute($column), $child->getKey()])->values();
    }

    /**
     * С `min()` поле обязательно: иначе пустая форма (ни одного блока) прошла бы проверку.
     */
    #[Override]
    protected function presenceRule(?Model $record): string
    {
        return ($this->minBlocks ?? 0) > 0 ? 'required' : parent::presenceRule($record);
    }

    #[Override]
    protected function typeRules(): array
    {
        return [
            ...parent::typeRules(),
            ...($this->minBlocks !== null ? ['min:'.$this->minBlocks] : []),
            ...($this->maxBlocks !== null ? ['max:'.$this->maxBlocks] : []),
        ];
    }

    /**
     * Свои блоки обновляются, новые создаются, отсутствующие в запросе — удаляются.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        $relation = $this->relationInstance($record);
        $existing = $relation->get()->keyBy(fn (Model $child): string => $this->childKey($child));
        $kept = [];
        $position = 0;

        foreach (self::blocks($value) as $block) {
            $id = $block['id'] === null ? null : (string) $block['id'];
            $child = $id === null ? null : $existing->get($id);

            if ($id !== null && ! $child instanceof Model) {
                continue;
            }

            if (! $child instanceof Model) {
                if ($this->isEmptyBlock($block['values'])) {
                    continue;
                }

                $child = $relation->make();
            }

            if ($this->orderColumn !== null) {
                $child->setAttribute($this->orderColumn, ++$position);
            }

            $this->saveChild($relation, $child, $block['values']);
            $kept[] = $this->childKey($child);
        }

        foreach ($existing as $key => $child) {
            if (! in_array((string) $key, $kept, true)) {
                if ($child->delete() === false) {
                    throw new OperationCancelled;
                }
            }
        }

        $record->unsetRelation($this->name);
    }

    /**
     * Ключ вложенной записи строкой.
     */
    private function childKey(Model $child): string
    {
        $key = $child->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
