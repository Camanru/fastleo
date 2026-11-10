<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\TracksSeenKeys;
use Fastleo\Support\OperationCancelled;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelation;
use InvalidArgumentException;
use Override;
use Throwable;

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
    use TracksSeenKeys;

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
     *
     * @return string|list<string>
     */
    #[Override]
    protected function presenceRule(?Model $record): string|array
    {
        return ($this->minBlocks ?? 0) > 0 ? 'required' : parent::presenceRule($record);
    }

    #[Override]
    protected function typeRules(): array
    {
        return [
            ...parent::typeRules(),
            ...($this->minBlocks !== null ? [$this->minBlocksRule($this->minBlocks)] : []),
            ...($this->maxBlocks !== null ? ['max:'.$this->maxBlocks] : []),
        ];
    }

    /**
     * Не меньше `$min` блоков, которые останутся после сохранения: уже существующие и заполненные новые. Пустой новый блок
     * не создаётся, поэтому счётчик `min:N` по сырому массиву позволил бы убрать все существующие блоки, добавив один пустой.
     */
    private function minBlocksRule(int $min): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min): void {
            try {
                $blocks = self::blocks($this->dehydrate($value));
            } catch (Throwable) {
                // Значение поля блока не разобрать (подделанный запрос: дата «abc»): ошибку по нему даст правило самого поля, а не 500.
                return;
            }

            $kept = array_filter($blocks, fn (array $block): bool => $block['id'] !== null || ! $this->isEmptyBlock($block['values']));

            if (count($kept) < $min) {
                $fail(__('fastleo::resources.has_many_min', ['min' => $min], Fastleo::LOCALE));
            }
        };
    }

    #[Override]
    public function getRules(?Model $record): array
    {
        return [...parent::getRules($record), ...$this->seenRules()];
    }

    /**
     * Свои блоки обновляются, новые создаются, отсутствующие в запросе — удаляются, но только те, что были в форме
     * при открытии (`seenKeys()`): строку, добавленную другим пользователем за время правки, устаревшая форма не стирает.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        $relation = $this->relationInstance($record);
        $existing = $relation->get()->keyBy(fn (Model $child): string => $this->childKey($child));
        $blocks = self::blocks($value);
        $kept = [];
        $position = 0;

        // Убранные блоки удаляются раньше записи остальных: уникальный индекс (родитель, выбранная запись)
        // не мешает убрать строку и добавить такую же заново.
        foreach ($blocks as $block) {
            if ($block['id'] !== null && $existing->has((string) $block['id'])) {
                $kept[] = (string) $block['id'];
            }
        }

        $seen = $this->seenKeys();

        foreach ($existing as $key => $child) {
            if (! in_array((string) $key, $kept, true) && ($seen === null || in_array((string) $key, $seen, true)) && $child->delete() === false) {
                throw new OperationCancelled();
            }
        }

        foreach ($blocks as $block) {
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
