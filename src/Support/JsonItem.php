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
        $item = new self();
        $item->setRawAttributes(array_filter($values, is_string(...), ARRAY_FILTER_USE_KEY), true);
        $item->exists = true;

        return $item;
    }

    /**
     * Отсутствующее подполе — пустое значение, а не ошибка: в JSON не обязаны быть все ключи (у `Seo` без заполненных
     * полей их нет, в старой записи нет ключа нового подполя), поэтому `Model::shouldBeStrict()` сайта не должен
     * ронять форму панели. Цена: опечатка в имени подполя в шаблоне сайта
     * (`$item->quetsion`) тоже даст `null`, а не исключение strict-режима.
     *
     * @param  string  $key
     */
    #[\Override]
    protected function throwMissingAttributeExceptionIfApplicable($key): mixed
    {
        return null;
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
