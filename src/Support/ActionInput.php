<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Actions\Action;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * Разбор ввода действия над записями: выбранные ключи и значения мини-формы.
 * Общий для страницы админки и MCP-сервера; от HTTP-запроса не зависит.
 */
final class ActionInput
{
    /**
     * Ключи записей: массив строк в формате ключа модели, не больше `fastleo.list.max_all`.
     * null — выбор неверный.
     *
     * @return list<string>|null
     */
    public static function ids(Resource $resource, mixed $ids): ?array
    {
        $max = max(1, config()->integer('fastleo.list.max_all', 1000));
        $model = $resource->newModel();

        if (! is_array($ids) || $ids === [] || count($ids) > $max) {
            return null;
        }

        $keys = [];

        foreach ($ids as $id) {
            $id = is_int($id) ? (string) $id : $id;

            if (! is_string($id) || ! RecordKey::valid($model, $id)) {
                return null;
            }

            $keys[$id] = $id;
        }

        return array_values($keys);
    }

    /**
     * Проверка значений мини-формы действия: правила его полей под ключом `data`.
     *
     * @param  array<array-key, mixed>  $input  ввод с ключом `data`
     */
    public static function validator(Action $action, array $input): ValidatorContract
    {
        $rules = ['data' => ['array']];
        $attributes = [];

        foreach (self::fields($action) as $field) {
            foreach ($field->getRules(null) as $key => $fieldRules) {
                $rules['data.'.$key] = $fieldRules;
            }

            foreach ($field->validationAttributes() as $key => $label) {
                $attributes['data.'.$key] = $label;
            }
        }

        return validator($input, $rules, ValidationMessages::all(), $attributes);
    }

    /**
     * Проверенные значения мини-формы в виде, готовом для `Action::handle()`.
     *
     * @return array<string, mixed>
     */
    public static function values(Action $action, ValidatorContract $validator): array
    {
        $validated = $validator->validated();
        $input = is_array($validated['data'] ?? null) ? $validated['data'] : [];
        $data = [];

        foreach (self::fields($action) as $field) {
            if (array_key_exists($field->name, $input)) {
                $data[$field->name] = $field->dehydrate($input[$field->name]);
            }
        }

        return $data;
    }

    /**
     * Поля мини-формы, которые админ может заполнить.
     *
     * @return list<Field>
     */
    private static function fields(Action $action): array
    {
        if (! $action->hasForm()) {
            return [];
        }

        return array_values(array_filter($action->schema()->fields(), fn (Field $field): bool => $field->isVisibleInForm(null) && $field->isWritable()));
    }
}
