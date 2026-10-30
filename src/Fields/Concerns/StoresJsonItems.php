<?php

declare(strict_types=1);

namespace Fastleo\Fields\Concerns;

use Fastleo\Fastleo;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Fields\Field;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Password;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Select;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Layout\Layout;
use Fastleo\Support\FormSchema;
use Fastleo\Support\JsonItem;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Общее у полей, которые хранят список элементов с подполями в JSON-колонке (Repeater, Blocks):
 * схема подполей, проверка каста `array`, запись значений подполей, варианты картинок.
 */
trait StoresJsonItems
{
    /**
     * Схема подполей с префиксом правил; запрещённые подполя — ошибка описания.
     *
     * @param  list<Field|Layout>  $items
     *
     * @throws LogicException подполе, которое внутри элемента не работает
     */
    protected function itemSchema(array $items, string $prefix): FormSchema
    {
        $schema = new FormSchema($items, JsonItem::class);

        foreach ($schema->fields() as $field) {
            $field->nestedIn($prefix);

            if ($field instanceof Nested || $field instanceof Repeater || $field instanceof Blocks || $field instanceof KeyValue || $field instanceof Seo
                || ($field instanceof SearchesOptions && ! $field instanceof Select) || $field->getDependsOn() !== null
                || $field instanceof Slug || $field instanceof Password || $field->isTranslatable()) {
                throw new LogicException(__('fastleo::exceptions.nested_field', ['field' => $field->name, 'parent' => $this->name, 'type' => class_basename($field)], Fastleo::LOCALE));
            }

            // Подполя JSON не записываются через fill(): значение вместо пустого сюда не доходит.
            if ($field->hasEmptyValue()) {
                throw new LogicException(__('fastleo::exceptions.empty_value_unsupported', ['field' => $field->name, 'type' => $field::class], Fastleo::LOCALE));
            }

            // Значение колонки собирается заново из формы: скрытое или нередактируемое подполе стёрлось бы.
            if ($field->hasRecordVisibility() || $field->hasAccessModifier() || ! $field->isWritable() || $field->isHidden()) {
                throw new LogicException(__('fastleo::exceptions.json_item_locked', ['field' => $field->name, 'parent' => $this->name], Fastleo::LOCALE));
            }
        }

        Nested::assertControllingFields($schema, $this->name);

        return $schema;
    }

    /**
     * Записываемые подполя схемы.
     *
     * @return list<Field>
     */
    protected static function writableItemFields(FormSchema $schema): array
    {
        return array_values(array_filter($schema->fields(), fn (Field $field): bool => $field->isVisibleInForm(null) && $field->isWritable()));
    }

    /**
     * Значения подполей одного элемента из запроса (только описанные подполя).
     *
     * @param  list<Field>  $fields
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function dehydrateItem(array $fields, array $input): array
    {
        $values = [];

        foreach ($fields as $field) {
            if (array_key_exists($field->name, $input)) {
                $values[$field->name] = $field->dehydrate($input[$field->name]);
            }
        }

        return $values;
    }

    /**
     * Элемент ничего не заполнен: каждое значение пустое или равно значению по умолчанию подполя.
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values
     */
    protected static function isEmptyItem(array $fields, array $values): bool
    {
        foreach ($fields as $field) {
            $value = $values[$field->name] ?? null;

            if (in_array($value, [null, '', [], false], true)) {
                continue;
            }

            if ($value != $field->untouchedValue()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Сохранённое значение колонки → список массивов (json-строка тоже понимается).
     *
     * @return list<array<array-key, mixed>>
     */
    protected static function storedItems(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * Модель требует каст `array` у колонки — иначе массив не сохранится.
     *
     * @throws LogicException у колонки нет каста
     */
    protected function assertArrayCast(Model $record): void
    {
        if (! $record->hasCast($this->name)) {
            throw new LogicException(__('fastleo::exceptions.field_needs_array_cast', ['field' => $this->name, 'model' => $record::class], Fastleo::LOCALE));
        }
    }

    /**
     * Поставить в очередь варианты новых картинок подполей Image и Gallery.
     *
     * @param  list<Field>  $fields
     * @param  list<array<array-key, mixed>>  $items  значения элементов сейчас
     * @param  list<array<array-key, mixed>>  $previous  значения до сохранения
     */
    protected static function queueItemVariants(array $fields, array $items, array $previous): void
    {
        foreach ($fields as $field) {
            if (! $field instanceof Image && ! $field instanceof Gallery) {
                continue;
            }

            $before = self::paths(array_column($previous, $field->name));
            $paths = array_diff(self::paths(array_column($items, $field->name)), $before);
            $field->queueVariants(array_values(array_unique($paths)));
        }
    }

    /**
     * Пути картинок из значений подполя (строка у Image, список у Gallery).
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function paths(array $values): array
    {
        $paths = [];

        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [$value] as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
