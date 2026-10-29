<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Override;

/**
 * Число ползунком. Границы по умолчанию — от 0 до 100, шаг 1; текущее значение видно рядом.
 *
 * ```php
 * Range::make('discount', 'Скидка, %')->min(0)->max(50)->step(5)->default(0);
 * ```
 */
class Range extends Number
{
    /** Целое число по умолчанию. */
    protected bool $integer = true;

    /** Нижняя граница ползунка. */
    protected int|float|null $min = 0;

    /** Верхняя граница ползунка. */
    protected int|float|null $max = 100;

    /**
     * Ползунок без значения стоит на нижней границе и отправляет её.
     */
    #[Override]
    public function untouchedValue(): mixed
    {
        return $this->dehydrate($this->formValue(null) ?? $this->getMin());
    }
}
