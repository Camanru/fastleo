<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Carbon\CarbonImmutable;
use Closure;
use Fastleo\Support\SqlExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Override;

/**
 * График: линия или столбцы по набору «подпись → число».
 *
 * ```php
 * Chart::make('Заявки за 30 дней')->line()->data(fn () => Chart::countByDay(Lead::query()));
 * Chart::make('По статусам')->bar()->data(fn () => ['Новые' => 4, 'В работе' => 9]);
 * ```
 *
 * Рисует Chart.js (отдельный файл подключается только на дашборде с графиком).
 */
final class Chart extends Widget
{
    /** Самое большее точек на графике. */
    private const int MAX_POINTS = 365;

    private string $type = 'line';

    /** @var (Closure(): array<array-key, mixed>)|null */
    private ?Closure $data = null;

    private ?string $label = null;

    /**
     * Линия.
     */
    public function line(): static
    {
        $this->type = 'line';

        return $this;
    }

    /**
     * Столбцы.
     */
    public function bar(): static
    {
        $this->type = 'bar';

        return $this;
    }

    /**
     * Данные: замыкание возвращает массив «подпись → число».
     *
     * @param  Closure(): array<array-key, int|float|numeric-string>  $data
     */
    public function data(Closure $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Подпись ряда во всплывающей подсказке (по умолчанию — заголовок виджета).
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Сколько записей создавалось в каждый из последних дней: «дд.мм» → число, дни без записей — нули.
     * Группировка идёт средствами базы (`date()`), записи не загружаются.
     *
     * ```php
     * Chart::countByDay(Lead::query(), 'created_at', 14); // последние 14 дней
     * ```
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>|class-string<TModel>  $query
     * @return array<int|string, int>
     *
     * @throws InvalidArgumentException небезопасное имя колонки
     */
    public static function countByDay(Builder|string $query, string $column = 'created_at', int $days = 30): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя колонки: '.$column);
        }

        $days = max(1, min(self::MAX_POINTS, $days));
        $query = is_string($query) ? $query::query() : clone $query;
        $start = CarbonImmutable::today()->subDays($days - 1);
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $base = $query->where($column, '>=', $start)->toBase();
        $counts = $base
            ->select([new SqlExpression('date('.$wrapped.') as day'), new SqlExpression('count(*) as total')])
            ->groupBy('day')
            ->pluck('total', 'day');

        $result = [];

        for ($day = $start; $day->lte(CarbonImmutable::today()); $day = $day->addDay()) {
            $total = $counts[$day->toDateString()] ?? 0;
            $result[$day->format('d.m')] = is_numeric($total) ? (int) $total : 0;
        }

        return $result;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.chart';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $points = $this->data instanceof Closure ? array_slice(($this->data)(), 0, self::MAX_POINTS, true) : [];
        $values = array_map(fn (mixed $value): int|float => is_numeric($value) ? $value + 0 : 0, array_values($points));

        return [
            'chart' => [
                'type' => $this->type,
                'label' => $this->label ?? $this->getTitle(),
                'labels' => array_map(strval(...), array_keys($points)),
                'values' => $values,
            ],
            'empty' => $values === [],
        ];
    }
}
