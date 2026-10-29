<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use BackedEnum;
use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Фильтр-список: показать записи с выбранным вариантом.
 *
 * Варианты — свои (`options()`: массив, PHP enum или замыкание) или из одноимённого поля
 * `Select`/`Enum`/`Radio` ресурса. Для `Select::multiple()` (json-массив) ищется запись,
 * в массиве которой есть выбранное значение. Значение не из вариантов не применяется.
 *
 * ```php
 * SelectFilter::make('status');                                   // варианты поля status
 * SelectFilter::make('city', 'Город')->options(['msk' => 'Москва', 'spb' => 'Санкт-Петербург']);
 * ```
 */
class SelectFilter extends Filter
{
    /**
     * Свои варианты или null — варианты одноимённого поля.
     *
     * @var array<array-key, string>|class-string<BackedEnum>|(Closure(): array<array-key, string>)|null
     */
    protected array|string|Closure|null $options = null;

    /**
     * Варианты фильтра.
     *
     * ```php
     * SelectFilter::make('type')->options(['news' => 'Новости', 'article' => 'Статьи']);
     * SelectFilter::make('status')->options(ArticleStatus::class);
     * ```
     *
     * @param  array<array-key, string>|class-string<BackedEnum>|(Closure(): array<array-key, string>)  $options
     */
    public function options(array|string|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Варианты: значение → подпись.
     *
     * @return array<array-key, string>
     *
     * @throws LogicException нет ни своих вариантов, ни поля-списка с тем же именем
     */
    public function getOptions(): array
    {
        $options = $this->options;

        if ($options === null) {
            $field = $this->field();

            if (! $field instanceof Select) {
                throw new LogicException(__('fastleo::exceptions.filter_options', ['filter' => $this->name], Fastleo::LOCALE));
            }

            return $field->getOptions();
        }

        if ($options instanceof Closure) {
            return $options();
        }

        if (is_string($options)) {
            return new Select($this->name)->options($options)->getOptions();
        }

        return $options;
    }

    /**
     * Строка-значение из вариантов или null.
     */
    #[Override]
    public function value(mixed $input): ?string
    {
        $value = self::string($input);

        if ($value === null) {
            return null;
        }

        foreach (array_keys($this->getOptions()) as $option) {
            if ((string) $option === $value) {
                return $value;
            }
        }

        return null;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::filters.select';
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        $field = $this->field();

        if ($this->options === null && $field instanceof Select && $field->isMultiple()) {
            $query->whereJsonContains($query->qualifyColumn($this->name), $value);

            return;
        }

        $query->where($query->qualifyColumn($this->name), $value);
    }
}
