<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fields\Blocks;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Date;
use Fastleo\Fields\Email;
use Fastleo\Fields\Field;
use Fastleo\Fields\FileField;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Time;
use Illuminate\Support\Str;

/**
 * Поиск полей с данными людей (почта, телефон) в описании раздела: по типу `Email`, `Tel` и по слову в имени —
 * `email`, `phone`, `tel`, `telephone`, `cellphone`, `whatsapp`, `viber` (с цифрой и во множественном числе, в любом
 * регистре: `client_phone`, `phone2`, `phones`, `phoneNumber`, `PHONE`). Галочки, файлы и даты по имени не проверяются:
 * `show_phone`, `email_sent`, `image_mobile` — не данные людей. По результату раздел сам закрывается от MCP-сервера.
 *
 * ```php
 * PersonalData::find($resource->schema()->fields()); // 'phone' или null
 * ```
 */
final class PersonalData
{
    /**
     * Имя первого поля с данными людей, в том числе внутри вложенных записей, повторителей и блоков; null — таких нет.
     *
     * @param  list<Field>  $fields
     */
    public static function find(array $fields): ?string
    {
        foreach ($fields as $field) {
            if (self::is($field)) {
                return $field->name;
            }

            $inner = match (true) {
                $field instanceof Nested, $field instanceof Repeater => self::find($field->schema()->fields()),
                $field instanceof Blocks => self::findInBlocks($field),
                default => null,
            };

            if ($inner !== null) {
                return $field->name.'.'.$inner;
            }
        }

        return null;
    }

    /**
     * Поле хранит почту или телефон.
     */
    private static function is(Field $field): bool
    {
        if ($field instanceof Email || $field instanceof Tel) {
            return true;
        }

        if ($field instanceof Checkbox || $field instanceof FileField || $field instanceof Date || $field instanceof Time) {
            return false;
        }

        return array_any([strtolower($field->name), Str::snake($field->name)], fn (string $name): bool => preg_match('/(^|_)(e_?mails?|phones?|tel|telephones?|cellphones?|whatsapp|viber)\d*(_|$)/', $name) === 1);
    }

    private static function findInBlocks(Blocks $field): ?string
    {
        foreach (array_keys($field->getTypes()) as $type) {
            $found = self::find($field->schema($type)->fields());

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
