<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Элемент JSON-поля (Repeater, Blocks) в виде модели без таблицы: подполя читают из неё значения
 * так же, как из обычной записи (`hydrate()`, условия показа, отображение).
 *
 * ```php
 * $item = JsonItem::from(['question' => 'Как записаться?', 'answer' => '...']);
 * ```
 */
#[Unguarded]
#[WithoutTimestamps]
final class JsonItem extends Model
{
    /**
     * Элемент из сохранённых значений.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function from(array $values): self
    {
        $item = new self;
        $item->setRawAttributes(array_filter($values, is_string(...), ARRAY_FILTER_USE_KEY), true);
        $item->exists = true;

        return $item;
    }

    /**
     * У элемента нет связей: подполе с именем метода модели (`from`) — обычное значение.
     *
     * @param  string  $key
     */
    #[\Override]
    public function isRelation($key): bool
    {
        return false;
    }
}
