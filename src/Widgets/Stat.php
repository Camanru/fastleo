<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Closure;
use Override;

/**
 * Плитка с одним числом (или короткой строкой): «Заявки сегодня — 12».
 *
 * ```php
 * Stat::make('Заявки сегодня')
 *     ->value(fn () => Lead::whereDate('created_at', today())->count())
 *     ->description(fn () => 'всего '.Lead::count())
 *     ->icon('inbox')
 *     ->url(route('fastleo.resources.index', 'leads'));
 * ```
 */
final class Stat extends Widget
{
    private Closure|int|float|string $value = '';

    private Closure|string|null $description = null;

    private string $icon = 'chart-no-axes-column';

    private ?string $url = null;

    /**
     * Значение или замыкание, которое его считает (вызывается при показе дашборда).
     *
     * @param  Closure(): (int|float|string)|int|float|string  $value
     */
    public function value(Closure|int|float|string $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Короткая подпись под значением.
     *
     * @param  Closure(): string|string  $description
     */
    public function description(Closure|string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Иконка Lucide.
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Ссылка с плитки (например, на раздел). Чужие схемы (`javascript:`) не выводятся.
     */
    public function url(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.stat';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $value = $this->value instanceof Closure ? ($this->value)() : $this->value;
        $description = $this->description instanceof Closure ? ($this->description)() : $this->description;
        $safe = $this->url !== null && preg_match('~^(https?://|/(?![/\\\\]))~i', $this->url) === 1;

        return [
            'value' => is_int($value) || is_float($value) ? number_format($value, is_float($value) ? 2 : 0, ',', ' ') : $value,
            'description' => $description,
            'icon' => $this->icon,
            'url' => $safe ? $this->url : null,
        ];
    }
}
