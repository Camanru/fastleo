<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Значения страницы настроек в виде модели без таблицы: поля читают и пишут значения так же, как у записи ресурса.
 * Каждое поле страницы имеет каст «как есть» (`PassThroughCast`).
 *
 * ```php
 * $record = SettingsRecord::from(['phone' => '+7 900 000-00-00', 'socials' => [...]], ['phone', 'socials']);
 * ```
 */
#[Unguarded]
#[WithoutTimestamps]
final class SettingsRecord extends Model
{
    /**
     * Запись из значений; $fields — имена всех полей страницы.
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $fields
     */
    public static function from(array $values, array $fields): self
    {
        $record = new self;
        $record->mergeCasts(array_fill_keys($fields, PassThroughCast::class));
        $record->setRawAttributes(array_intersect_key($values, array_flip($fields)), true);
        $record->exists = true;

        return $record;
    }

    /**
     * У записи нет связей: поле с именем метода модели — обычное значение.
     *
     * @param  string  $key
     */
    #[\Override]
    public function isRelation($key): bool
    {
        return false;
    }
}
