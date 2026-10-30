<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

/**
 * Правило «значение — один из вариантов поля». Видит все данные формы, поэтому варианты
 * зависимого списка (`dependsOn`) считаются по значению поля-зависимости из того же запроса.
 *
 * ```php
 * new OptionRule(fn (mixed $value, array $data): bool => isset($options[$value]), 'in');
 * ```
 */
final class OptionRule implements DataAwareRule, Stringable, ValidationRule
{
    /**
     * Данные формы.
     *
     * @var array<array-key, mixed>
     */
    private array $data = [];

    /**
     * @param  Closure(mixed, array<array-key, mixed>): bool  $passes  проверка значения
     * @param  string  $message  правило, текст которого показать (`in`, `exists`)
     * @param  string|null  $description  как правило выглядит в `fastleo:inspect` (`in:"a","b"`); по умолчанию — имя класса
     */
    public function __construct(private readonly Closure $passes, private readonly string $message, private readonly ?string $description = null) {}

    /**
     * Описание правила для `fastleo:inspect`.
     */
    public function __toString(): string
    {
        return $this->description ?? self::class;
    }

    /**
     * Данные формы от валидатора.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Проверить значение.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ($this->passes)($value, $this->data)) {
            $fail(ValidationMessages::line($this->message));
        }
    }
}
