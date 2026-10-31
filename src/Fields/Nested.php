<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Layout\Layout;
use Fastleo\Support\FormSchema;
use Fastleo\Support\OperationCancelled;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\HtmlString;
use LogicException;
use Override;

/**
 * Вложенные записи прямо в форме родителя: строки прайса, пункты меню, карточка «Баннер».
 * Имя поля — метод связи модели (`HasMany` или `HasOne`); поля блока описываются `fields()`.
 *
 * Блок с `id` меняется, только если он принадлежит этой записи — чужой `id` из запроса игнорируется.
 * Блоки сохраняются после записи родителя в той же транзакции, через Eloquent (`save()`, `delete()`).
 */
abstract class Nested extends Field
{
    /** Вложенные блоки не показываются в таблице списка. */
    protected bool $showInList = false;

    /**
     * Поля и раскладка блока.
     *
     * @var list<Field|Layout>
     */
    protected array $items = [];

    /** Схема блока (поля привязаны к модели связи). */
    private ?FormSchema $schema = null;

    /**
     * Поля блока. Внутри — простые поля, файлы и RichText (без связей, вложенных блоков, Slug и Password).
     *
     * ```php
     * HasMany::make('prices', 'Цены')->fields([Text::make('title')->required(), Number::make('price')]);
     * ```
     *
     * @param  list<Field|Layout>  $fields
     */
    public function fields(array $fields): static
    {
        $this->items = $fields;
        $this->schema = null;

        return $this;
    }

    /**
     * Блок один (HasOne) или их список (HasMany).
     */
    abstract public function isSingle(): bool;

    /**
     * Связь модели того вида, которого ждёт поле (`HasMany` или `HasOne`).
     */
    abstract protected function matchesRelation(mixed $relation): bool;

    /**
     * Связь записи (или пустой модели ресурса).
     *
     * @return HasOneOrMany<Model, Model, mixed>
     *
     * @throws LogicException у модели нет такого метода связи
     */
    public function relationInstance(?Model $record = null): HasOneOrMany
    {
        $model = $record ?? $this->model();
        $relation = method_exists($model, $this->name) ? $model->{$this->name}() : null;

        if (! $relation instanceof HasOneOrMany || ! $this->matchesRelation($relation)) {
            throw new LogicException(__('fastleo::exceptions.relation_missing', [
                'field' => $this->name, 'model' => $model::class, 'method' => $this->name, 'type' => class_basename(static::class),
            ], Fastleo::LOCALE));
        }

        return $relation;
    }

    /**
     * Схема полей блока; поля привязаны к модели связи.
     *
     * @throws LogicException внутри блока поле, которое там не работает
     */
    public function schema(): FormSchema
    {
        if ($this->schema instanceof FormSchema) {
            return $this->schema;
        }

        $schema = new FormSchema($this->items, $this->relationInstance()->getRelated()::class);

        foreach ($schema->fields() as $field) {
            $field->nestedIn($this->isSingle() ? $this->name.'.' : $this->name.'.*.');

            if ($field instanceof Nested || $field instanceof Repeater || $field instanceof Blocks || ($field instanceof SearchesOptions && ! $field instanceof Select) || $field->getDependsOn() !== null || $field instanceof Slug || $field instanceof Password || $field->isTranslatable()) {
                throw new LogicException(__('fastleo::exceptions.nested_field', ['field' => $field->name, 'parent' => $this->name, 'type' => class_basename($field)], Fastleo::LOCALE));
            }

            // Правила блоков общие для всех блоков, поэтому видимость поля не может зависеть от записи блока.
            if ($field->hasRecordVisibility()) {
                throw new LogicException(__('fastleo::exceptions.nested_visibility', ['field' => $field->name, 'parent' => $this->name], Fastleo::LOCALE));
            }
        }

        self::assertControllingFields($schema, $this->name);

        return $this->schema = $schema;
    }

    /**
     * Управляющие поля `visibleWhen()` подполей: то же вложенное поле без ограничений доступа.
     * Поле верхнего уровня формы или недоступное админу в блок не приходит, и зависимое поле никогда не сохранилось бы.
     *
     * @throws LogicException управляющее поле не из блока или с `readonly()`, `disabled()`, `hidden()`
     */
    public static function assertControllingFields(FormSchema $schema, string $parent): void
    {
        $fields = [];

        foreach ($schema->fields() as $field) {
            $fields[$field->name] = $field;
        }

        foreach ($fields as $field) {
            $control = $field->getVisibleWhen()['field'] ?? null;

            if ($control !== null && (! isset($fields[$control]) || $fields[$control]->hasAccessModifier())) {
                throw new LogicException(__('fastleo::exceptions.nested_controlling', ['field' => $field->name, 'parent' => $parent, 'control' => $control], Fastleo::LOCALE));
            }
        }
    }

    /**
     * Записываемые поля блока.
     *
     * @return list<Field>
     */
    public function blockFields(?Model $child): array
    {
        return array_values(array_filter($this->schema()->fields(), fn (Field $field): bool => $field->isVisibleInForm($child) && $field->isWritable()));
    }

    /**
     * Вложенные записи для формы.
     *
     * @return Collection<int, Model>
     */
    public function children(Model $record): Collection
    {
        $related = $record->getRelationValue($this->name);

        if ($related instanceof Model) {
            return new Collection([$related]);
        }

        return $related instanceof Collection ? $related : new Collection;
    }

    #[Override]
    public function hydrate(Model $record): mixed
    {
        return $this->children($record);
    }

    #[Override]
    protected function formatDefault(mixed $value): mixed
    {
        return new Collection;
    }

    /**
     * Правила самого поля и полей блока с префиксом (`prices.*.title` или `banner.title`).
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $prefix = $this->isSingle() ? $this->name.'.' : $this->name.'.*.';
        $int = $this->relationInstance()->getRelated()->getKeyType() === 'int';

        if (! $this->isSingle()) {
            $rules[$prefix.'id'] = ['nullable', $int ? 'integer' : 'string'];
        }

        foreach ($this->blockFields(null) as $field) {
            foreach ($field->getRules(null) as $key => $fieldRules) {
                $rules[$prefix.$key] = $fieldRules;
            }
        }

        return $rules;
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['array'];
    }

    /**
     * Подписи полей блока для текстов ошибок.
     *
     * @return array<string, string>
     */
    #[Override]
    public function validationAttributes(): array
    {
        $attributes = parent::validationAttributes();
        $prefix = $this->isSingle() ? $this->name.'.' : $this->name.'.*.';

        foreach ($this->schema()->fields() as $field) {
            foreach ($field->validationAttributes() as $key => $label) {
                $attributes[$prefix.$key] = $label;
            }
        }

        return $attributes;
    }

    /**
     * Блоки из запроса → список `['id' => ключ или null, 'values' => [поле => значение]]` в порядке формы.
     *
     * @return list<array{id: int|string|null, values: array<string, mixed>}>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $blocks = $this->isSingle() ? [$value] : (is_array($value) ? $value : []);
        $result = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $id = $block['id'] ?? null;
            $values = [];

            foreach ($this->blockFields(null) as $field) {
                if (array_key_exists($field->name, $block)) {
                    $values[$field->name] = $field->dehydrate($block[$field->name]);
                }
            }

            $result[] = ['id' => is_int($id) || (is_string($id) && $id !== '') ? $id : null, 'values' => $values];
        }

        return $result;
    }

    /**
     * Блоки пишутся после сохранения родителя.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        //
    }

    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    /**
     * Результат `dehydrate()` (из `afterSave`) → проверенный список блоков.
     *
     * @return list<array{id: int|string|null, values: array<string, mixed>}>
     */
    protected static function blocks(mixed $value): array
    {
        $blocks = [];

        foreach (is_array($value) ? $value : [] as $block) {
            if (! is_array($block) || ! is_array($block['values'] ?? null)) {
                continue;
            }

            $values = [];

            foreach ($block['values'] as $name => $item) {
                if (is_string($name)) {
                    $values[$name] = $item;
                }
            }

            $id = $block['id'] ?? null;
            $blocks[] = ['id' => is_int($id) || is_string($id) ? $id : null, 'values' => $values];
        }

        return $blocks;
    }

    /**
     * Новый блок, в котором админ ничего не заполнил (такой не создаётся): каждое значение пустое
     * или равно значению по умолчанию поля — галочка, ползунок и цвет отправляются всегда.
     *
     * @param  array<string, mixed>  $values
     */
    protected function isEmptyBlock(array $values): bool
    {
        foreach ($this->blockFields(null) as $field) {
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
     * Записать значения в блок и сохранить его в связи родителя.
     *
     * @param  HasOneOrMany<Model, Model, mixed>  $relation
     * @param  array<string, mixed>  $values
     */
    protected function saveChild(HasOneOrMany $relation, Model $child, array $values): void
    {
        $fields = $this->blockFields($child);

        foreach ($fields as $field) {
            if ($field->writesAttribute() && ! $child->isFillable($field->name)) {
                throw new LogicException(__('fastleo::exceptions.field_not_fillable', [
                    'field' => $field->name, 'resource' => $this->name, 'model' => $child::class,
                ], Fastleo::LOCALE));
            }
        }

        foreach ($fields as $field) {
            if (array_key_exists($field->name, $values)) {
                $field->fill($child, $values[$field->name]);
            }
        }

        foreach ($fields as $field) {
            $field->beforeSave($child);
        }

        if ($relation->save($child) === false) {
            throw new OperationCancelled;
        }

        foreach ($fields as $field) {
            if (array_key_exists($field->name, $values)) {
                $field->afterSave($child, $values[$field->name]);
            }
        }
    }

    /**
     * На просмотре — таблица блоков (HasMany) или список «поле: значение» (HasOne).
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof \Closure) {
            return parent::display($record);
        }

        $children = $this->children($record);

        if ($children->isEmpty()) {
            return null;
        }

        return new HtmlString(view('fastleo::fields.nested-show', [
            'field' => $this,
            'children' => $children,
            'fields' => array_values(array_filter($this->schema()->fields(), fn (Field $field): bool => ! $field instanceof Hidden)),
        ])->render());
    }

    /**
     * Не выгружается в Excel/CSV.
     */
    #[Override]
    public function isExportable(): bool
    {
        return false;
    }

    /**
     * Пустое значение здесь имеет свой смысл (галочка, пароль, файл, список, вложенные записи) — `emptyValue()` недоступен.
     */
    #[Override]
    protected function supportsEmptyValue(): bool
    {
        return false;
    }
}
