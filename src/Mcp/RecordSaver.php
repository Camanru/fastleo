<?php

declare(strict_types=1);

namespace Fastleo\Mcp;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Password;
use Fastleo\Resource;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ValidationMessages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Создание и изменение записи по значениям от агента: те же поля, проверка и запись, что у формы админки
 * (`RecordWriter`). Меняются только присланные поля; значения, которых нет среди записываемых полей, — ошибка.
 */
final class RecordSaver
{
    /**
     * Сохранить запись.
     *
     * @param  array<array-key, mixed>  $values  значения полей по именам
     *
     * @throws ToolFailed раздел только для чтения, неизвестное поле, ошибка проверки или базы
     */
    public static function save(Resource $resource, ?Model $existing, array $values): Model
    {
        if ($resource::isReadonly()) {
            throw new ToolFailed(__('fastleo::mcp.readonly'));
        }

        $writable = $resource->writableFields($existing);
        $unknown = array_diff(array_map(strval(...), array_keys($values)), array_map(fn (Field $field): string => $field->name, $writable));

        if ($unknown !== []) {
            throw new ToolFailed(__('fastleo::mcp.unknown_fields', ['fields' => implode(', ', $unknown)]));
        }

        $fields = $existing instanceof Model ? array_values(array_filter($writable, fn (Field $field): bool => array_key_exists($field->name, $values))) : $writable;
        $data = self::normalize($values);

        // Агент присылает пароль один раз: подтверждение (`confirmed()`) совпадает с ним.
        foreach ($fields as $field) {
            if ($field instanceof Password && $field->isConfirmed() && isset($data[$field->name]) && ! array_key_exists($field->name.'_confirmation', $data)) {
                $data[$field->name.'_confirmation'] = $data[$field->name];
            }
        }

        if ($existing instanceof Model) {
            $data = self::keepOthers($existing, $fields, $data);
            [$fields, $data] = self::recheckDependent($writable, $fields, $existing, $data);
        }

        if (! $existing instanceof Model) {
            foreach ($fields as $field) {
                $default = $field->formValue(null);

                // Только простые значения по умолчанию; связи и вложенные записи отдают объекты — они не данные.
                if (! array_key_exists($field->name, $data) && ((is_scalar($default) && $default !== '') || (is_array($default) && $default !== []))) {
                    $data[$field->name] = $default;
                }
            }
        }

        // Управляющего поля (visibleWhen, dependsOn) нет среди присланных — условие проверяется по значению записи.
        foreach ($resource->schema()->fields() as $field) {
            foreach (array_filter([$field->getVisibleWhen()['field'] ?? null, $field->getDependsOn()]) as $controlling) {
                if ($existing instanceof Model && ! array_key_exists($controlling, $data)) {
                    $data[$controlling] = $resource->controllingValue($controlling, $existing, null);
                }
            }
        }

        $input = RecordWriter::controllingInput($resource, $existing, $data);
        $validator = validator($input, RecordWriter::rules($resource, $existing, $fields), ValidationMessages::all(), RecordWriter::attributes($fields));

        if ($validator->fails()) {
            throw new ToolFailed(__('fastleo::mcp.validation', ['errors' => json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]));
        }

        $record = $existing ?? $resource->newModel();

        try {
            RecordWriter::write($resource, $record, $fields, RecordWriter::inInputOrder($validator->validated(), $input));
        } catch (QueryException $exception) {
            throw new ToolFailed(DatabaseErrors::explain($exception), $exception->getCode(), $exception);
        } catch (OperationCancelled) {
            throw new ToolFailed(__('fastleo::resources.save_cancelled', locale: Fastleo::LOCALE));
        }

        return $record;
    }

    /**
     * Частичное изменение не должно стирать то, чего агент не присылал:
     * переводы на других языках сохраняются, а вложенные записи без `allow_delete` не удаляются.
     *
     * @param  list<Field>  $fields
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     *
     * @throws ToolFailed из присланного списка пропали существующие вложенные записи, а удаление отключено
     */
    private static function keepOthers(Model $existing, array $fields, array $data): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field->name, $data)) {
                continue;
            }

            if ($field->isTranslatable() && is_array($data[$field->name])) {
                $data[$field->name] = array_replace($field->translations($existing), $data[$field->name]);
            }

            if ($field instanceof Nested && ! config()->boolean('fastleo.mcp.allow_delete')) {
                $kept = [];

                foreach ($field->dehydrate($data[$field->name]) as $block) {
                    if ($block['id'] !== null) {
                        $kept[] = (string) $block['id'];
                    }
                }

                $missing = $field->children($existing)->map(fn (Model $child): string => is_scalar($child->getKey()) ? (string) $child->getKey() : '')->reject(fn (string $key): bool => in_array($key, $kept, true));

                if ($missing->isNotEmpty()) {
                    throw new ToolFailed(__('fastleo::mcp.nested_delete', ['field' => $field->name, 'ids' => $missing->implode(', ')]));
                }
            }
        }

        return $data;
    }

    /**
     * Зависимое поле (`dependsOn()`), которого агент не присылал, но чьё управляющее поле меняется,
     * проверяется со своим сохранённым значением: оно могло перестать подходить.
     *
     * @param  list<Field>  $writable
     * @param  list<Field>  $fields
     * @param  array<array-key, mixed>  $data
     * @return array{0: list<Field>, 1: array<array-key, mixed>}
     */
    private static function recheckDependent(array $writable, array $fields, Model $existing, array $data): array
    {
        $names = array_map(fn (Field $field): string => $field->name, $fields);

        foreach ($writable as $field) {
            $stored = $existing->getAttribute($field->name);

            if (in_array($field->name, $names, true) || ! array_key_exists((string) $field->getDependsOn(), $data) || ! is_scalar($stored)) {
                continue;
            }

            $fields[] = $field;
            $data[$field->name] = (string) $stored;
        }

        return [$fields, $data];
    }

    /**
     * Значения JSON приводятся к виду формы: числа и логические — строками (`1`/`0`), как приходят из браузера.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalize(array $values): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => self::normalize($value),
            default => $value,
        }, $values);
    }
}
