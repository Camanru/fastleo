<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use DateTimeInterface;
use Fastleo\Fastleo;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * Дата и время. Колонка `dateTime`/`timestamp`, в модели — каст `'datetime'` (необязательно).
 *
 * Показывается и вводится в часовом поясе админки (`fastleo.timezone`), а в базу пишется
 * в поясе сайта (`app.timezone`) — как и всё остальное в Laravel.
 *
 * ```php
 * DateTime::make('starts_at', 'Начало')->required();
 * ```
 */
class DateTime extends Date
{
    /**
     * Дата и время для `<input type="datetime-local">` в поясе админки.
     */
    #[Override]
    public function hydrate(Model $record): ?string
    {
        return $this->inAdminTimezone($record->getAttribute($this->name))?->format('Y-m-d\TH:i');
    }

    /**
     * `ГГГГ-ММ-ДД ЧЧ:ММ` в поясе админки.
     */
    #[Override]
    public function exportValue(Model $record): ?string
    {
        return $this->inAdminTimezone($record->getAttribute($this->name))?->format('Y-m-d H:i');
    }

    /**
     * Ячейка-дата Excel, `ГГГГ-ММ-ДД ЧЧ:ММ[:СС]` или `ДД.ММ.ГГГГ ЧЧ:ММ` (пояс админки) → значение формы.
     */
    #[Override]
    public function importValue(mixed $cell): ?string
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m-d\TH:i');
        }

        $value = self::cellString($cell);

        return match (true) {
            $value === null => null,
            preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $value, $match) === 1 => "{$match[1]}T{$match[2]}",
            preg_match('/^(\d{2})\.(\d{2})\.(\d{4}) (\d{2}:\d{2})$/', $value, $match) === 1 => "{$match[3]}-{$match[2]}-{$match[1]}T{$match[4]}",
            default => $value,
        };
    }

    /**
     * Введённое в поясе админки → строка `Y-m-d H:i:s` в поясе сайта; пустое — null.
     */
    #[Override]
    public function dehydrate(mixed $value): ?string
    {
        $value = parent::dehydrate($value);

        if (! is_string($value)) {
            return null;
        }

        $date = \Illuminate\Support\Facades\Date::createFromFormat('Y-m-d\TH:i', $value, Fastleo::timezone());

        return $date?->setTimezone(Fastleo::appTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * Дата и время по умолчанию (`now()`, Carbon) → значение поля в поясе админки.
     */
    #[Override]
    protected function formatDefault(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? \Illuminate\Support\Facades\Date::instance($value)->setTimezone(Fastleo::timezone())->format('Y-m-d\TH:i')
            : $value;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['date_format:Y-m-d\TH:i'];
    }

    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        return $this->inAdminTimezone($value)?->format('d.m.Y H:i');
    }

    /**
     * Значение атрибута (в поясе сайта) → Carbon в поясе админки.
     */
    private function inAdminTimezone(mixed $value): ?Carbon
    {
        return self::toCarbon($value, Fastleo::appTimezone())?->setTimezone(Fastleo::timezone());
    }
}
