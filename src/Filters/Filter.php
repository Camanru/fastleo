<?php

declare(strict_types=1);

namespace Fastleo\Filters;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Фильтр списка раздела: значение приходит из адреса `?filter[имя]=…`, недопустимое значение
 * не применяется (фильтр считается выключенным).
 *
 * Готовые фильтры: `SelectFilter`, `BooleanFilter`, `BelongsToFilter`, `BelongsToManyFilter`, `DateRangeFilter`.
 * Свой запрос вместо условия по колонке — `using()`:
 *
 * ```php
 * public function filters(): array
 * {
 *     return [
 *         SelectFilter::make('status'),                       // варианты из поля status
 *         BooleanFilter::make('is_published', 'Опубликовано'),
 *         SelectFilter::make('popular', 'Популярность')
 *             ->options(['yes' => 'Больше 1000 просмотров'])
 *             ->using(fn (Builder $query, string $value) => $query->where('views', '>', 1000)),
 *     ];
 * }
 * ```
 */
abstract class Filter
{
    /**
     * Свой запрос фильтра (запрос, проверенное значение) или null — условие по колонке с именем фильтра.
     * Тип без сигнатуры: значение у каждого фильтра своё (string у списка, bool у «Да/Нет»).
     */
    protected ?Closure $using = null;

    /** Ресурс, к списку которого привязан фильтр. */
    protected ?Resource $resource = null;

    /**
     * @param  string  $name  имя в адресе и колонка по умолчанию
     *
     * @throws InvalidArgumentException имя не похоже на имя колонки
     */
    final public function __construct(public readonly string $name, /** Подпись над фильтром или null — подпись одноимённого поля ресурса. */
        protected ?string $label = null)
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $name], Fastleo::LOCALE));
        }
    }

    /**
     * Создать фильтр.
     *
     * ```php
     * BooleanFilter::make('is_published', 'Опубликовано');
     * ```
     */
    public static function make(string $name, ?string $label = null): static
    {
        return new static($name, $label);
    }

    /**
     * Подпись над фильтром.
     *
     * ```php
     * SelectFilter::make('status')->label('Состояние');
     * ```
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Свой запрос вместо условия по колонке: замыкание получает запрос и проверенное значение.
     *
     * ```php
     * BooleanFilter::make('has_photo', 'С фото')->using(fn (Builder $query, bool $value) => $value ? $query->whereNotNull('photo') : $query->whereNull('photo'));
     * ```
     *
     * @param  Closure  $query  fn (Builder $query, mixed $value): mixed
     */
    public function using(Closure $query): static
    {
        $this->using = $query;

        return $this;
    }

    /**
     * Привязать к ресурсу: фильтр берёт подпись и варианты из одноимённого поля.
     *
     * @internal вызывает `Resource::listFilters()`
     */
    public function forResource(Resource $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Подпись: своя, иначе подпись одноимённого поля, иначе имя.
     */
    public function getLabel(): string
    {
        return $this->label ?? $this->field()?->getLabel() ?? Str::headline($this->name);
    }

    /**
     * Проверенное значение из адреса или null — фильтр не применяется.
     */
    abstract public function value(mixed $input): mixed;

    /**
     * Применить фильтр с проверенным значением.
     *
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->using instanceof Closure) {
            ($this->using)($query, $value);

            return;
        }

        $this->query($query, $value);
    }

    /**
     * Шаблон фильтра: получает `filter`, `value` (проверенное значение или null), `form` (id формы списка).
     */
    abstract public function view(): string;

    /**
     * Условие по колонке, когда нет `using()`.
     *
     * @param  Builder<Model>  $query
     */
    abstract protected function query(Builder $query, mixed $value): void;

    /**
     * Одноимённое поле ресурса или null.
     */
    protected function field(): ?Field
    {
        return $this->resource?->schema()->field($this->name);
    }

    /**
     * Ресурс фильтра.
     *
     * @throws \LogicException фильтр не привязан к ресурсу
     */
    protected function resource(): Resource
    {
        return $this->resource ?? throw new \LogicException(__('fastleo::exceptions.filter_unbound', ['filter' => $this->name], Fastleo::LOCALE));
    }

    /**
     * Строка из адреса (не массив), обрезанная по краям, не длиннее 255 символов; пустая — null.
     */
    protected static function string(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $input = mb_substr(trim($input), 0, 255);

        return $input === '' ? null : $input;
    }
}
