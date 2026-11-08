<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Filters;

use Fastleo\Filters\Filter;
use Fastleo\Support\ContainsExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Свой тип фильтра витрины (создан `fastleo:make-filter ContainsText`): строка «содержит текст» по одной колонке,
 * без учёта регистра. Подключается в `filters()` ресурса: `ContainsText::make('description', 'В описании')`.
 */
final class ContainsText extends Filter
{
    /**
     * Проверенное значение из адреса или null — фильтр выключен.
     */
    #[Override]
    public function value(mixed $input): ?string
    {
        return is_string($input) && $input !== '' && mb_strlen($input) <= 255 ? $input : null;
    }

    /**
     * Шаблон фильтра: строка поиска над списком.
     */
    #[Override]
    public function view(): string
    {
        return 'fastleo.filters.contains-text';
    }

    /**
     * Колонка с именем фильтра содержит введённый текст (`%` и `_` ищутся как обычные символы).
     *
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        if (is_string($value)) {
            ContainsExpression::where($query, $query->qualifyColumn($this->name), $value);
        }
    }
}
