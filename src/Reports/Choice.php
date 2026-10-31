<?php

declare(strict_types=1);

namespace Fastleo\Reports;

use Closure;
use Override;

/**
 * Список значений в отчёте («Менеджер», «Статус»): один вариант или «Все». Значение для отчёта — ключ варианта строкой
 * (`$filters->get('manager')`) или `null`, если выбрано «Все». Значение не из вариантов отбрасывается.
 *
 * Значение по умолчанию — ключ варианта или замыкание, которое его вернёт (например, «менеджер — я»).
 *
 * ```php
 * Choice::make('manager', 'Менеджер')
 *     ->options(fn () => User::query()->where('is_manager', true)->pluck('name', 'id')->all())
 *     ->default(fn () => auth()->user()->is_manager ? (string) auth()->id() : null);
 * ```
 */
final class Choice extends ReportFilter
{
    /** @var array<array-key, string>|Closure(): array<array-key, string> */
    private array|Closure $options = [];

    /**
     * Варианты: массив «ключ → подпись» или замыкание, которое его вернёт.
     *
     * @param  array<array-key, string>|Closure(): array<array-key, string>  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Варианты «ключ → подпись».
     *
     * @return array<array-key, string>
     */
    public function getOptions(): array
    {
        return $this->options instanceof Closure ? ($this->options)() : $this->options;
    }

    #[Override]
    public function resolve(mixed $input, bool $given): ?string
    {
        $value = $given ? $input : $this->defaultValue();

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return array_key_exists((string) $value, $this->getOptions()) ? (string) $value : null;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::reports.choice';
    }
}
