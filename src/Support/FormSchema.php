<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Layout\Layout;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Описание формы ресурса: дерево полей и контейнеров из `fields()` и плоский список полей.
 */
final readonly class FormSchema
{
    /**
     * Все поля в порядке появления.
     *
     * @var list<Field>
     */
    private array $fields;

    /**
     * @param  list<Field|Layout>  $items  результат `Resource::fields()`
     * @param  class-string<Model>|null  $model  модель ресурса: к ней привязываются все поля (null — без привязки)
     *
     * @throws InvalidArgumentException два поля с одним именем или условие показа по полю с несколькими значениями
     */
    public function __construct(private array $items, ?string $model = null)
    {
        $fields = [];

        foreach (self::flatten($items) as $field) {
            if (isset($fields[$field->name])) {
                throw new InvalidArgumentException(__('fastleo::exceptions.field_duplicate', ['name' => $field->name], Fastleo::LOCALE));
            }

            $fields[$field->name] = $model === null ? $field : $field->forModel($model);
        }

        foreach ($fields as $field) {
            $controlling = $fields[$field->getVisibleWhen()['field'] ?? ''] ?? null;

            if ($controlling instanceof Field && $controlling->isMultiple()) {
                throw new InvalidArgumentException(__('fastleo::exceptions.visible_when_multiple', ['field' => $field->name, 'controlling' => $controlling->name], Fastleo::LOCALE));
            }
        }

        $this->fields = array_values($fields);
    }

    /**
     * Дерево полей и контейнеров как в `fields()`.
     *
     * @return list<Field|Layout>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Все поля без контейнеров.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Поле по имени или null.
     */
    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Поля из дерева по порядку.
     *
     * @param  list<Field|Layout>  $items
     * @return list<Field>
     */
    private static function flatten(array $items): array
    {
        $fields = [];

        foreach ($items as $item) {
            if ($item instanceof Field) {
                $fields[] = $item;
            } else {
                $fields = [...$fields, ...self::flatten($item->children())];
            }
        }

        return $fields;
    }
}
