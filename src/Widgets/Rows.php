<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Closure;
use Override;

/**
 * Таблица со своими строками и итоговой строкой: отчёт по менеджерам, продажи по дням. В отличие от `Table`, которая
 * показывает последние записи раздела, строки здесь считает сайт.
 *
 * Значения — строки, числа и `null` (выводятся как текст, числа выравниваются вправо и разделяются пробелами).
 *
 * ```php
 * Rows::make('Менеджеры')
 *     ->columns(['Менеджер', 'Заявок', 'Выручка'])
 *     ->rows(fn () => Manager::withCount('offers')->get()->map(fn ($m) => [$m->name, $m->offers_count, $m->revenue])->all())
 *     ->footer(fn () => ['Итого', Offer::count(), Offer::sum('price')])
 *     ->columnSpan(4);
 * ```
 */
final class Rows extends Widget
{
    /** Самое большее строк: больше таблице незачем, а страница должна оставаться лёгкой. */
    private const int MAX_ROWS = 200;

    /** @var list<string> */
    private array $columns = [];

    /** @var (Closure(): array<array-key, array<array-key, mixed>>)|null */
    private ?Closure $source = null;

    /** @var (Closure(): array<array-key, mixed>)|null */
    private ?Closure $footer = null;

    /**
     * Заголовки столбцов.
     *
     * @param  list<string>  $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Строки: замыкание возвращает список строк, каждая — список значений по порядку столбцов.
     *
     * @param  Closure(): array<array-key, array<array-key, mixed>>  $rows
     */
    public function rows(Closure $rows): static
    {
        $this->source = $rows;

        return $this;
    }

    /**
     * Итоговая строка под таблицей: замыкание возвращает значения по порядку столбцов.
     *
     * @param  Closure(): array<array-key, mixed>  $footer
     */
    public function footer(Closure $footer): static
    {
        $this->footer = $footer;

        return $this;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.rows';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $rows = $this->source instanceof Closure ? array_slice(array_values(($this->source)()), 0, self::MAX_ROWS) : [];

        return [
            'columns' => $this->columns,
            'rows' => array_map($this->cells(...), $rows),
            'footer' => $this->footer instanceof Closure ? $this->cells(($this->footer)()) : null,
        ];
    }

    /**
     * Ячейки строки: по числу столбцов, текст и признак числа.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<array{text: string, number: bool}>
     */
    private function cells(array $values): array
    {
        $cells = [];

        foreach (array_slice(array_values($values), 0, max(1, count($this->columns))) as $value) {
            $number = is_int($value) || is_float($value);
            $cells[] = [
                'text' => match (true) {
                    is_int($value) => number_format($value, 0, ',', ' '),
                    is_float($value) => number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2, ',', ' '),
                    is_scalar($value) => (string) $value,
                    default => '',
                },
                'number' => $number,
            ];
        }

        return $cells;
    }
}
