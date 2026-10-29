<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Число: целое (`integer()`) или дробное. Колонка `integer`, `decimal` или `float`.
 *
 * Сам проверяет: число, целое для `integer()`, границы `min()`/`max()`.
 *
 * ```php
 * Number::make('price', 'Цена')->min(0)->step(0.01);
 * Number::make('quantity', 'Количество')->integer()->min(1);
 * ```
 */
class Number extends Field
{
    /** Только целые числа. */
    protected bool $integer = false;

    /** Наименьшее значение. */
    protected int|float|null $min = null;

    /** Наибольшее значение. */
    protected int|float|null $max = null;

    /** Шаг стрелок в поле; null — 1 для целых, любой для дробных. */
    protected int|float|null $step = null;

    /**
     * Только целые числа.
     *
     * ```php
     * Number::make('quantity')->integer();
     * ```
     */
    public function integer(bool $integer = true): static
    {
        $this->integer = $integer;

        return $this;
    }

    /**
     * Наименьшее значение.
     *
     * ```php
     * Number::make('price')->min(0);
     * ```
     */
    public function min(int|float $min): static
    {
        $this->min = $min;

        return $this;
    }

    /**
     * Наибольшее значение.
     *
     * ```php
     * Number::make('rating')->max(5);
     * ```
     */
    public function max(int|float $max): static
    {
        $this->max = $max;

        return $this;
    }

    /**
     * Шаг стрелок в поле.
     *
     * ```php
     * Number::make('price')->step(0.01);
     * ```
     */
    public function step(int|float $step): static
    {
        $this->step = $step;

        return $this;
    }

    /**
     * Только целые числа.
     */
    public function isInteger(): bool
    {
        return $this->integer;
    }

    /**
     * Наименьшее значение или null.
     */
    public function getMin(): int|float|null
    {
        return $this->min;
    }

    /**
     * Наибольшее значение или null.
     */
    public function getMax(): int|float|null
    {
        return $this->max;
    }

    /**
     * Шаг для атрибута step: число или `any`.
     */
    public function getStep(): string
    {
        if ($this->step !== null) {
            return (string) $this->step;
        }

        return $this->integer ? '1' : 'any';
    }

    /**
     * Целое — в int, дробное — строкой как введено (точность decimal не теряется); пустое — null.
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = parent::dehydrate($value);

        if ($this->integer && is_numeric($value)) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Число ячейкой-числом (в Excel его можно складывать).
     */
    #[Override]
    public function exportValue(Model $record): int|float|string|null
    {
        $value = $record->getAttribute($this->name);

        return match (true) {
            is_int($value), is_float($value) => $value,
            is_numeric($value) => str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value,
            default => self::exportScalar($value),
        };
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        $rules = [$this->integer ? 'integer' : 'numeric'];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return $rules;
    }
}
