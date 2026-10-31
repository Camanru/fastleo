<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Actions\Action;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Field;
use Fastleo\Fields\FileField;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Filters\Filter;
use Fastleo\Page;
use Fastleo\Related;
use Fastleo\Resource;
use Fastleo\Settings;
use Illuminate\Support\Facades\Gate;
use Stringable;
use Throwable;

/**
 * Описание конфигурации админки простыми массивами: разделы, их поля, правила, связи, фильтры, действия и права.
 * Источник для `fastleo:inspect` и MCP-инструмента `describe-resource`; от HTTP-запроса не зависит.
 *
 * ```php
 * $info = (new ResourceInspector)->resource(Fastleo::resources()->find('articles'));
 * $info['fields'][0]['rules']; // ['title' => ['required', 'string', 'max:255']]
 * ```
 */
final class ResourceInspector
{
    /** Сколько вариантов Select показывать в описании поля. */
    private const int MAX_OPTIONS = 50;

    /**
     * Описание раздела. Если раздел описан с ошибкой, вместо полей вернётся ключ `error` с текстом.
     *
     * @return array<string, mixed>
     */
    public function resource(Resource $resource): array
    {
        $info = [
            'slug' => $resource::slug(),
            'class' => $resource::class,
            'label' => $resource::label(),
            'singular' => $resource::singular(),
            'group' => $resource::group(),
            'icon' => $resource::icon(),
            'position' => $resource::position(),
            'in_menu' => $resource::inMenu(),
            // Число у пункта меню: у ресурса переопределён `badge()`.
            'has_badge' => new \ReflectionMethod($resource, 'badge')->getDeclaringClass()->getName() !== Resource::class,
        ];

        try {
            $model = $resource::model();
            $policy = Gate::getPolicyFor($model);

            return [
                ...$info,
                'model' => $model,
                'table' => $resource->newModel()->getTable(),
                'policy' => is_object($policy) ? $policy::class : null,
                'readonly' => $resource::isReadonly(),
                'soft_deletes' => $resource::usesSoftDeletes(),
                'sort_column' => $resource::sortColumn(),
                'tree_column' => $resource::treeColumn(),
                'exportable' => $resource::isExportable(),
                'importable' => $resource::isImportable(),
                'import_key' => $resource::importKey(),
                'mcp' => $resource->isAvailableInMcp(),
                'poll' => $resource::poll(),
                'history_panel' => $resource::hasHistoryPanel(),
                'fields' => $this->fields($resource->schema()->fields()),
                'filters' => array_values(array_map($this->filter(...), $resource->listFilters())),
                'actions' => array_values(array_map($this->action(...), $resource->listActions())),
                'relations' => array_values(array_map($this->related(...), $resource->listRelations())),
            ];
        } catch (Throwable $e) {
            return [...$info, 'error' => $e->getMessage()];
        }
    }

    /**
     * Описание страницы настроек: подпись и поля.
     *
     * @return array<string, mixed>
     */
    public function settings(Settings $settings): array
    {
        $info = ['slug' => $settings::slug(), 'class' => $settings::class, 'label' => $settings::label()];

        try {
            return [...$info, 'fields' => $this->fields($settings->schema()->fields())];
        } catch (Throwable $e) {
            return [...$info, 'error' => $e->getMessage()];
        }
    }

    /**
     * Описание своей страницы.
     *
     * @return array<string, mixed>
     */
    public function page(Page $page): array
    {
        return [
            'slug' => $page::slug(),
            'class' => $page::class,
            'label' => $page::label(),
            'view' => $page->view(),
        ];
    }

    /**
     * Описание полей: тип, правила, флаги и сведения по типам (связь, файлы, варианты).
     *
     * @param  list<Field>  $fields
     * @return list<array<string, mixed>>
     */
    public function fields(array $fields): array
    {
        return array_map($this->field(...), $fields);
    }

    /**
     * Описание одного поля.
     *
     * @return array<string, mixed>
     */
    public function field(Field $field): array
    {
        $info = [
            'name' => $field->name,
            'type' => $field->type(),
            'class' => $field::class,
            'label' => $field->getLabel(),
            'required' => $field->isRequired(),
            'readonly' => $field->isReadonly(),
            'writes_attribute' => $field->writesAttribute(),
            'multiple' => $field->isMultiple(),
            'translatable' => $field->isTranslatable(),
            'in_form' => $field->isFormField(),
            'in_list' => $field->isVisibleInList(),
            'searchable' => $field->isSearchable(),
            'sortable' => $field->isSortable(),
            'rules' => $this->rules($field),
        ];

        return [...$info, ...$this->details($field)];
    }

    /**
     * Сведения, специфичные для типа поля.
     *
     * @return array<string, mixed>
     */
    private function details(Field $field): array
    {
        $details = [];

        if ($field instanceof BelongsTo || $field instanceof BelongsToMany) {
            $details['relation'] = $field->getRelationName();
            $details['related_model'] = $this->attempt(fn (): string => $field->relatedModel()::class);
        }

        if ($field instanceof Nested) {
            $details['related_model'] = $this->attempt(fn (): string => $field->relationInstance()->getRelated()::class);
            $details['fields'] = $this->fields($field->schema()->fields());
        }

        if ($field instanceof Checkbox && $field->isQuickToggle()) {
            $details['quick_toggle'] = true;
        }

        if ($field instanceof Select && $field->hasBadges()) {
            $details['badges'] = true;
        }

        if ($field->isFromQuery()) {
            $details['from_query'] = true;
        }

        if ($field->isInlineEditable()) {
            $details['inline_editable'] = true;
        }

        if ($field instanceof FileField) {
            $details['folder'] = $field->uploadFolder();
            $details['extensions'] = $field->acceptedExtensions();
        }

        if ($field->hasEmptyValue()) {
            $details['empty_value'] = $field->getEmptyValue();
        }

        if ($field instanceof Select) {
            $options = $this->attempt(fn (): array => $field->getOptions());
            $details['options'] = is_array($options) && count($options) <= self::MAX_OPTIONS ? $options : null;
        }

        if ($field instanceof Text || $field instanceof Textarea) {
            $details['max_length'] = $field->getMaxLength();
        }

        return $details;
    }

    /**
     * Правила проверки поля текстом: строки как есть, объекты — через `__toString()` или именем класса.
     *
     * @return array<string, list<string>>
     */
    private function rules(Field $field): array
    {
        $rules = $this->attempt(fn (): array => $field->getRules(null));

        if (! is_array($rules)) {
            return [];
        }

        $result = [];

        foreach ($rules as $key => $list) {
            $result[(string) $key] = array_map(
                static fn (mixed $rule): string => is_string($rule) ? $rule : ($rule instanceof Stringable ? (string) $rule : get_debug_type($rule)),
                $list,
            );
        }

        return $result;
    }

    /**
     * @return array{name: string, label: string, resource: class-string, slug: string, foreign_key: string, expandable: bool, aside: bool, columns: list<string>, quick_add: list<string>, add_label: string}
     */
    private function related(Related $related): array
    {
        return [
            'name' => $related->name,
            'label' => $related->getLabel(),
            'resource' => $related->resource,
            'slug' => $related->child()::slug(),
            'foreign_key' => $related->foreignKey(),
            'expandable' => $related->isExpandable(),
            'aside' => $related->isAside(),
            'columns' => array_map(fn (Field $field): string => $field->name, $related->tableFields()),
            'quick_add' => array_map(fn (Field $field): string => $field->name, $related->quickAddFields()),
            'add_label' => $related->getAddLabel(),
        ];
    }

    /**
     * @return array{name: string, label: string, class: class-string, has_default: bool}
     */
    private function filter(Filter $filter): array
    {
        return ['name' => $filter->name, 'label' => $filter->getLabel(), 'class' => $filter::class, 'has_default' => $filter->hasDefault()];
    }

    /**
     * @return array{key: string, label: string, ability: string, bulk: bool, single: bool, danger: bool, class: class-string}
     */
    private function action(Action $action): array
    {
        return [
            'key' => $action->key(),
            'label' => $action->getLabel(),
            'ability' => $action->getAbility(),
            'bulk' => $action->isBulk(),
            'single' => $action->isSingle(),
            'danger' => $action->isDanger(),
            'class' => $action::class,
        ];
    }

    /**
     * Результат замыкания или null, если оно упало (поле описано с ошибкой — её покажет `fastleo:doctor`).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
