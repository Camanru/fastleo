<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Carbon\CarbonImmutable;
use Closure;
use Fastleo\Fastleo;
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

    /** @var (Closure(): array<array-key, array<array-key, int|float|numeric-string>>)|null */
    private ?Closure $series = null;

    /** Самое большее линий на одном графике. */
    private const int MAX_SERIES = 8;

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
     * Несколько линий (столбцов) на одном графике: замыкание возвращает «название линии → (подпись точки → число)».
     * Подписи точек объединяются в порядке появления, пропущенное — ноль; над графиком показывается легенда.
     * Заменяет `data()`.
     *
     * ```php
     * Chart::make('Динамика')->series(fn () => ['Заявки' => ['01.03' => 4, '02.03' => 7], 'Продажи' => ['01.03' => 1, '02.03' => 3]]);
     * ```
     *
     * @param  Closure(): array<array-key, array<array-key, int|float|numeric-string>>  $series
     */
    public function series(Closure $series): static
    {
        $this->series = $series;

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
     * Группировка идёт средствами базы, записи не загружаются. Дни считаются в поясе админки (`fastleo.timezone`);
     * если он отличается от пояса сайта, база группирует по часам, а в дни их складывает PHP (поэтому в поясах со
     * сдвигом в полчаса запись у границы суток может попасть в соседний день).
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
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $siteZone = Fastleo::appTimezone();
        $hour = Fastleo::timezone() === $siteZone ? null : self::hourExpression($query->getModel()->getConnection()->getDriverName(), $wrapped);
        $zone = $hour === null ? $siteZone : Fastleo::timezone();

        $today = CarbonImmutable::now($zone)->startOfDay();
        $start = $today->subDays($days - 1);

        $counts = $query->where($column, '>=', $start->setTimezone($siteZone))->toBase()
            ->select([new SqlExpression(($hour ?? 'date('.$wrapped.')').' as day'), new SqlExpression('count(*) as total')])
            ->groupBy('day')
            ->pluck('total', 'day');

        $perDay = [];

        foreach ($counts as $bucket => $total) {
            $day = $hour === null ? (string) $bucket : CarbonImmutable::createFromFormat('!Y-m-d H', (string) $bucket, $siteZone)?->setTimezone($zone)->toDateString();

            if ($day !== null) {
                $perDay[$day] = ($perDay[$day] ?? 0) + (is_numeric($total) ? (int) $total : 0);
            }
        }

        $result = [];

        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            $result[$day->format('d.m')] = $perDay[$day->toDateString()] ?? 0;
        }

        return $result;
    }

    /**
     * Выражение «год-месяц-день час» для группировки по часам; null — драйвер не поддерживается (группировка по дням).
     */
    private static function hourExpression(string $driver, string $wrapped): ?string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d %H', ".$wrapped.')',
            'mysql', 'mariadb' => 'date_format('.$wrapped.", '%Y-%m-%d %H')",
            'pgsql' => 'to_char('.$wrapped.", 'YYYY-MM-DD HH24')",
            default => null,
        };
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
        if ($this->series instanceof Closure) {
            return $this->seriesPayload(($this->series)());
        }

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

    /**
     * Данные нескольких линий: общие подписи точек, значения по линиям.
     *
     * @param  array<array-key, array<array-key, mixed>>  $series
     * @return array<string, mixed>
     */
    private function seriesPayload(array $series): array
    {
        $series = array_slice($series, 0, self::MAX_SERIES, true);
        $labels = [];

        foreach ($series as $points) {
            foreach (array_keys($points) as $label) {
                $labels[(string) $label] = true;
            }
        }

        $labels = array_slice(array_keys($labels), 0, self::MAX_POINTS);
        $datasets = [];

        foreach ($series as $name => $points) {
            $byLabel = [];

            foreach ($points as $label => $value) {
                $byLabel[(string) $label] = is_numeric($value) ? $value + 0 : 0;
            }

            $datasets[] = ['label' => (string) $name, 'values' => array_map(fn (string $label): int|float => $byLabel[$label] ?? 0, $labels)];
        }

        return [
            'chart' => ['type' => $this->type, 'label' => $this->label ?? $this->getTitle(), 'labels' => array_map(strval(...), $labels), 'datasets' => $datasets],
            'empty' => $labels === [],
        ];
    }
}
