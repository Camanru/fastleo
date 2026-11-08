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

            // Repeater и Blocks внутри элемента — один уровень вложенности: внутри них самих они уже не работают.
            if (($field instanceof Repeater || $field instanceof Blocks) && $this->rulePrefix !== '') {
                throw new LogicException(__('fastleo::exceptions.nested_depth', ['field' => $field->name, 'parent' => $this->name, 'type' => class_basename($field)], Fastleo::LOCALE));
            }

            if ($field instanceof Nested || $field instanceof KeyValue || $field instanceof Seo
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
     * Метка ключа в шаблоне нового элемента: у списка внутри элемента своя (`__KEY_1__`), иначе замена ключа внешнего
     * элемента задела бы и шаблон внутреннего.
     */
    public function keyPlaceholder(): string
    {
        $level = substr_count($this->rulePrefix, '*');

        return $level === 0 ? '__KEY__' : '__KEY_'.$level.'__';
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
     * Значения подполей элемента в том виде, в котором их принимает запись: вложенные Repeater и Blocks приводятся
     * от сохранённого вида к виду формы.
     *
     * @param  list<Field>  $fields
     * @param  array<array-key, mixed>  $item
     * @return array<array-key, mixed>
     */
    protected static function inputItem(array $fields, array $item): array
    {
        foreach ($fields as $field) {
            if (($field instanceof Repeater || $field instanceof Blocks) && array_key_exists($field->name, $item)) {
                $item[$field->name] = $field->inputValue($item[$field->name]);
            }
        }

        return $item;
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

            if (! $field->isUntouched($value)) {
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
     * Поставить в очередь варианты новых картинок подполей Image и Gallery, в том числе во вложенных Repeater и Blocks.
     *
     * @param  list<Field>  $fields
     * @param  list<array<array-key, mixed>>  $items  значения элементов сейчас
     * @param  list<array<array-key, mixed>>  $previous  значения до сохранения
     */
    protected static function queueItemVariants(array $fields, array $items, array $previous): void
    {
        foreach ($fields as $field) {
            if ($field instanceof Repeater || $field instanceof Blocks) {
                $field->queueVariantsOf(self::flatItems(array_column($items, $field->name)), self::flatItems(array_column($previous, $field->name)));

                continue;
            }

            if (! $field instanceof Image && ! $field instanceof Gallery) {
                continue;
            }

            $before = self::paths(array_column($previous, $field->name));
            $paths = array_diff(self::paths(array_column($items, $field->name)), $before);
            $field->queueVariants(array_values(array_unique($paths)));
        }
    }

    /**
     * Пути картинок подполей Image и Gallery с их размерами (`variants()`), в том числе во вложенных Repeater и Blocks:
     * путь → размеры. Для `fastleo:images`, которая пересоздаёт варианты.
     *
     * @param  list<Field>  $fields
     * @param  list<array<array-key, mixed>>  $items  значения элементов
     * @return array<string, list<string>>
     */
    protected static function itemVariantPaths(array $fields, array $items): array
    {
        $result = [];

        foreach ($fields as $field) {
            if ($field instanceof Repeater || $field instanceof Blocks) {
                $found = $field->variantPathsOf(self::flatItems(array_column($items, $field->name)));
            } elseif ($field instanceof Image || $field instanceof Gallery) {
                $found = array_fill_keys(self::paths(array_column($items, $field->name)), $field->getVariants());
            } else {
                continue;
            }

            foreach ($found as $path => $presets) {
                $result[$path] = array_values(array_unique([...$result[$path] ?? [], ...$presets]));
            }
        }

        return $result;
    }

    /**
     * Элементы вложенного списка из значений подполя всех внешних элементов — одним списком.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<array<array-key, mixed>>
     */
    private static function flatItems(array $values): array
    {
        return array_merge(...array_map(self::storedItems(...), $values));
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
