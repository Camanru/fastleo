<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use DateTimeInterface;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Throwable;

/**
 * Дата без времени. Колонка `date`, в модели — каст `'date'` (необязательно).
 *
 * Вводится календарём браузера, хранится как `ГГГГ-ММ-ДД`, в списке — `ДД.ММ.ГГГГ`.
 *
 * ```php
 * Date::make('published_on', 'Дата публикации')->default(fn () => now()->toDateString());
 * ```
 */
class Date extends Field
{
    /**
     * Дата для `<input type="date">`.
     */
    #[Override]
    public function hydrate(Model $record): ?string
    {
        return self::toCarbon($record->getAttribute($this->name))?->format('Y-m-d');
    }

    /**
     * Ячейка-дата Excel или строка `ДД.ММ.ГГГГ` → `ГГГГ-ММ-ДД`.
     */
    #[Override]
    public function importValue(mixed $cell): ?string
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m-d');
        }

        $value = self::cellString($cell);

        return $value !== null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $match) === 1 ? "{$match[3]}-{$match[2]}-{$match[1]}" : $value;
    }

    /**
     * Дата по умолчанию (`now()`, Carbon) → `ГГГГ-ММ-ДД`.
     */
    #[Override]
    protected function formatDefault(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? \Illuminate\Support\Facades\Date::instance($value)->format('Y-m-d') : $value;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['date_format:Y-m-d'];
    }

    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        return self::toCarbon($value)?->format('d.m.Y');
    }

    /**
     * Значение атрибута в Carbon или null, если это не дата.
     */
    protected static function toCarbon(mixed $value, ?string $timezone = null): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return \Illuminate\Support\Facades\Date::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Date::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
