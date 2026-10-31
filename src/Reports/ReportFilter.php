<?php

declare(strict_types=1);

namespace Fastleo\Reports;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Фильтр страницы-отчёта (`Page::filters()`): период, список. Значение приходит из адреса `?filter[имя]=…`; ключа нет —
 * значение по умолчанию, ключ есть, но пуст — «без ограничения». Готовые фильтры: `Period`, `Choice`.
 *
 * ```php
 * public function filters(): array
 * {
 *     return [Period::make('period', 'Период')->default('month'), Choice::make('manager', 'Менеджер')->options(fn () => Manager::pluck('name', 'id')->all())];
 * }
 * ```
 */
abstract class ReportFilter
{
    /** Значение по умолчанию или замыкание, которое его вернёт. */
    protected mixed $default = null;

    /**
     * @param  string  $name  имя в адресе и в `Filters`
     *
     * @throws InvalidArgumentException имя не похоже на имя колонки
     */
    final public function __construct(public readonly string $name, protected ?string $label = null)
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $name], Fastleo::LOCALE));
        }
    }

    /**
     * Создать фильтр.
     */
    public static function make(string $name, ?string $label = null): static
    {
        return new static($name, $label);
    }

    /**
     * Значение по умолчанию (замыкание вычисляется при каждом показе страницы).
     */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Подпись над фильтром.
     */
    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    /**
     * Значение по умолчанию как оно есть: замыкание вызывается.
     */
    protected function defaultValue(): mixed
    {
        return $this->default instanceof Closure ? ($this->default)() : $this->default;
    }

    /**
     * Значение фильтра для отчёта.
     *
     * @param  mixed  $input  значение из адреса (если ключ был)
     * @param  bool  $given  ключ `filter[имя]` есть в адресе (даже пустой)
     */
    abstract public function resolve(mixed $input, bool $given): mixed;

    /**
     * Blade-шаблон поля в панели фильтров: получает `filter`, `value` (результат `resolve()`), `page` (адрес страницы).
     */
    abstract public function view(): string;
}
