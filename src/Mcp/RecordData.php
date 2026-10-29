<?php

declare(strict_types=1);

namespace Fastleo\Mcp;

use Fastleo\Fields\Field;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Password;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Запись раздела в виде массива для ответа агенту: только описанные в ресурсе поля,
 * без паролей, скрытых и недоступных полей.
 */
final class RecordData
{
    /**
     * Строка списка: ключ и значения полей списка текстом (как в экспорте).
     *
     * @return array<string, mixed>
     */
    public static function row(Resource $resource, Model $record): array
    {
        $row = ['id' => $record->getKey()];

        foreach ($resource->listFields() as $field) {
            if ($field->isExportable() && ! $field instanceof Password) {
                $row[$field->name] = $field->exportValue($record);
            }
        }

        return $row;
    }

    /**
     * Все значения формы записи: то, что агент может прочитать и записать обратно.
     *
     * @return array<string, mixed>
     */
    public static function detail(Resource $resource, Model $record): array
    {
        $values = [];

        foreach ($resource->formFields($record) as $field) {
            if (! $field instanceof Password) {
                $values[$field->name] = self::value($field, $record);
            }
        }

        return ['id' => $record->getKey(), 'values' => $values];
    }

    /**
     * Значение поля: переводы — по языкам, остальное — как в форме.
     */
    private static function value(Field $field, Model $record): mixed
    {
        if ($field instanceof Nested) {
            return self::nested($field, $record);
        }

        return $field->isTranslatable() ? $field->translations($record) : $field->hydrate($record);
    }

    /**
     * Вложенные записи (HasMany — список, HasOne — одна или null): ключ и только поля блока,
     * без служебных колонок вроде внешнего ключа и дат.
     *
     * @return list<array<string, mixed>>|array<string, mixed>|null
     */
    private static function nested(Nested $field, Model $record): ?array
    {
        $blocks = [];

        foreach ($field->children($record) as $child) {
            $block = ['id' => $child->getKey()];

            foreach ($field->schema()->fields() as $blockField) {
                if ($blockField->isVisibleInForm($child) && ! $blockField instanceof Password) {
                    $block[$blockField->name] = $blockField->hydrate($child);
                }
            }

            $blocks[] = $block;
        }

        return $field->isSingle() ? ($blocks[0] ?? null) : $blocks;
    }
}
