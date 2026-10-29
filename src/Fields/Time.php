<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use DateTimeInterface;
use Fastleo\Fastleo;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Override;

/**
 * Время без даты (часы и минуты). Колонка `time`.
 *
 * ```php
 * Time::make('opens_at', 'Открытие')->default('09:00');
 * ```
 */
class Time extends Field
{
    /**
     * Время `ЧЧ:ММ` для `<input type="time">` (секунды из базы отбрасываются).
     */
    #[Override]
    public function hydrate(Model $record): ?string
    {
        return $this->short($record->getAttribute($this->name));
    }

    /**
     * Ячейка-время Excel или `ЧЧ:ММ:СС` → `ЧЧ:ММ`.
     */
    #[Override]
    public function importValue(mixed $cell): ?string
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('H:i');
        }

        $value = self::cellString($cell);

        return $value !== null && preg_match('/^(\d{2}:\d{2}):\d{2}$/', $value, $match) === 1 ? $match[1] : $value;
    }

    /**
     * Время по умолчанию (`now()`, Carbon) → `ЧЧ:ММ` в поясе админки.
     */
    #[Override]
    protected function formatDefault(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? Date::instance($value)->setTimezone(Fastleo::timezone())->format('H:i')
            : $value;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['date_format:H:i'];
    }

    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        return $this->short($value);
    }

    /**
     * `09:00:00` → `09:00`.
     */
    private function short(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) === 1 ? substr($value, 0, 5) : null;
    }
}
