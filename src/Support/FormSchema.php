<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Image;
use Fastleo\Fields\MorphTo;
use Fastleo\Layout\Layout;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Related;
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
     * @param  list<Field|Layout|Related>  $items  результат `Resource::fields()`
     * @param  class-string<Model>|null  $model  модель ресурса: к ней привязываются все поля (null — без привязки)
     * @param  bool  $inResource  схема `fields()` раздела: только там связанные таблицы показываются (в настройках, действиях
     *                            и вложенных полях их некому вывести)
     *
     * @throws InvalidArgumentException два поля с одним именем, условие показа по полю с несколькими значениями
     *                                  или связанная таблица вне раздела или вне вкладки
     */
    public function __construct(private array $items, ?string $model = null, bool $inResource = false)
    {
        $fields = [];

        foreach (self::flatten($items, $inResource) as $field) {
            if (isset($fields[$field->name])) {
                throw new InvalidArgumentException(__('fastleo::exceptions.field_duplicate', ['name' => $field->name], Fastleo::LOCALE));
            }

            $fields[$field->name] = $model === null ? $field : $field->forModel($model);
        }

        foreach ($fields as $field) {
            $dependency = $fields[$field->getDependsOn() ?? ''] ?? null;

            if ($dependency instanceof MorphTo) {
                throw new InvalidArgumentException(__('fastleo::exceptions.depends_on_morph', ['field' => $field->name, 'controlling' => $field->getDependsOn()], Fastleo::LOCALE));
            }

            if ($dependency instanceof Field && $dependency->isMultiple()) {
                throw new InvalidArgumentException(__('fastleo::exceptions.depends_on_multiple', ['field' => $field->name, 'controlling' => $dependency->name], Fastleo::LOCALE));
            }

            $controlling = $fields[$field->getVisibleWhen()['field'] ?? ''] ?? null;

            if ($controlling instanceof Field && $controlling->isMultiple()) {
                throw new InvalidArgumentException(__('fastleo::exceptions.visible_when_multiple', ['field' => $field->name, 'controlling' => $controlling->name], Fastleo::LOCALE));
            }
        }

        foreach ($fields as $field) {
            if ($field->isInlineEditable()) {
                $this->assertInlineEditable($field, $fields);
            }
        }

        $this->fields = array_values($fields);
    }

    /**
     * Поле с `inlineEditable()` не зависит от других полей формы и они от него.
     *
     * @param  array<string, Field>  $fields
     *
     * @throws InvalidArgumentException поле зависит от других или другие от него
     */
    private function assertInlineEditable(Field $field, array $fields): void
    {
        $reason = match (true) {
            $field->getDependsOn() !== null || $field->getVisibleWhen() !== null => trans()->string('fastleo::exceptions.inline_reason_depends', [], Fastleo::LOCALE),
            $field->isMultiple() || $field->isTranslatable() || $field->isFromQuery() => trans()->string('fastleo::exceptions.inline_reason_multiple', [], Fastleo::LOCALE),
            default => null,
        };

        foreach ($fields as $other) {
            if ($reason === null && ($other->getDependsOn() === $field->name || ($other->getVisibleWhen()['field'] ?? null) === $field->name)) {
                $reason = trans()->string('fastleo::exceptions.inline_reason_controlling', ['other' => $other->name], Fastleo::LOCALE);
            }
        }

        if ($reason !== null) {
            throw new InvalidArgumentException(__('fastleo::exceptions.inline_conflict', ['field' => $field->name, 'reason' => $reason], Fastleo::LOCALE));
        }
    }

    /**
     * Дерево полей и контейнеров как в `fields()`.
     *
     * @return list<Field|Layout|Related>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Связанные таблицы, положенные во вкладки, с ключом своей вкладки.
     *
     * @return list<array{related: Related, key: string, topLevel: bool}>
     */
    public function tabTables(): array
    {
        $tables = [];

        $walk = function (array $items, bool $topLevel) use (&$walk, &$tables): void {
            foreach ($items as $item) {
                if ($item instanceof Tabs) {
                    foreach ($item->children() as $tab) {
                        if ($tab instanceof Tab) {
                            foreach ($tab->tables() as $related) {
                                $tables[] = ['related' => $related, 'key' => $tab->key(), 'topLevel' => $topLevel];
                            }
                        }
                    }
                }

                if ($item instanceof Layout) {
                    $walk($item->children(), $topLevel && $item instanceof Tabs);
                }
            }
        };

        $walk($this->items, true);

        return $tables;
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
     * @param  list<Field|Layout|Related>  $items
     * @return list<Field>
     *
     * @throws InvalidArgumentException связанная таблица вне раздела или не во вкладке
     */
    private static function flatten(array $items, bool $inResource, bool $inTab = false): array
    {
        $fields = [];

        foreach ($items as $item) {
            if ($item instanceof Field) {
                $fields[] = $item;

                // Описание картинки (`Image::alt()`) — отдельное поле схемы, форма рисует его в шаблоне картинки.
                if ($item instanceof Image && $item->altField() instanceof Field) {
                    $fields[] = $item->altField();
                }
            } elseif ($item instanceof Related && ! $inResource) {
                throw new InvalidArgumentException(__('fastleo::exceptions.related_not_in_resource', ['relation' => $item->name], Fastleo::LOCALE));
            } elseif ($item instanceof Related && ! $inTab) {
                throw new InvalidArgumentException(__('fastleo::exceptions.related_outside_tab', ['relation' => $item->name], Fastleo::LOCALE));
            } elseif ($item instanceof Layout) {
                $fields = [...$fields, ...self::flatten($item->children(), $inResource, $item instanceof Tab)];
            }
        }

        return $fields;
    }
}
