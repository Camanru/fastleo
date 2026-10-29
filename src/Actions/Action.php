<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Fastleo\Fastleo;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Fields\Field;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Password;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Layout\Layout;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\FormSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Действие над одной записью или над выбранными в списке: «Опубликовать», «Отправить письмо».
 *
 * Кнопка появляется в меню строки, на страницах просмотра и редактирования (`$single`)
 * и в полосе выбранных записей (`$bulk`). Перед запуском действие может спросить подтверждение
 * (`$confirm`) или показать мини-форму (`fields()`). Право — способность Policy `$ability`
 * на каждую запись: записи без права пропускаются.
 *
 * ```php
 * final class PublishArticles extends Action
 * {
 *     protected ?string $label = 'Опубликовать';
 *     protected string $icon = 'send';
 *     protected ?string $confirm = 'Опубликовать выбранные статьи?';
 *
 *     public function handle(Collection $records, array $data): ActionResult
 *     {
 *         $records->each(fn (Article $article) => $article->update(['is_published' => true]));
 *
 *         return ActionResult::success("Опубликовано: {$records->count()}");
 *     }
 * }
 *
 * // В ресурсе:
 * public function actions(): array
 * {
 *     return [new PublishArticles];
 * }
 * ```
 */
abstract class Action
{
    /** Ключ в адресе; null — из имени класса. */
    protected ?string $key = null;

    /** Подпись кнопки; null — из имени класса. */
    protected ?string $label = null;

    /** Иконка Lucide. */
    protected string $icon = 'zap';

    /** Вопрос перед запуском («Опубликовать выбранные статьи?») или null — без подтверждения. */
    protected ?string $confirm = null;

    /** Опасное действие: красная кнопка подтверждения. */
    protected bool $danger = false;

    /** Способность Policy, которую проверяет каждая запись (`update`, `delete` или свой метод Policy: `publish`). */
    protected string $ability = 'update';

    /** Кнопка у одной записи: меню строки, просмотр, редактирование. */
    protected bool $single = true;

    /** Кнопка в полосе выбранных записей списка. */
    protected bool $bulk = true;

    /** Действие можно запустить через MCP-сервер (`run-action`); `false` — только человеком в админке. */
    protected bool $mcp = true;

    /** Ресурс, к которому привязано действие. */
    protected ?Resource $resource = null;

    /** Поля мини-формы (считаются один раз). */
    private ?FormSchema $schema = null;

    /**
     * Выполнить действие над записями, на которые у админа есть право.
     *
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data  значения мини-формы (`fields()`), уже проверенные
     */
    abstract public function handle(Collection $records, array $data): ActionResult;

    /**
     * Поля мини-формы перед запуском (простые поля: текст, число, дата, список, галочка, картинка).
     *
     * ```php
     * public function fields(): array
     * {
     *     return [Date::make('published_on', 'Дата публикации')->required()];
     * }
     * ```
     *
     * @return list<Field|Layout>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Ключ действия в адресе: kebab-case имени класса без «Action» (`PublishArticles` → `publish-articles`).
     */
    public function key(): string
    {
        return $this->key ?? Str::kebab(Str::replaceEnd('Action', '', class_basename(static::class)) ?: class_basename(static::class));
    }

    /**
     * Подпись кнопки.
     */
    public function getLabel(): string
    {
        return $this->label ?? Str::headline(Str::replaceEnd('Action', '', class_basename(static::class)));
    }

    /**
     * Иконка кнопки.
     */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * Вопрос перед запуском или null.
     */
    public function getConfirm(): ?string
    {
        return $this->confirm;
    }

    /**
     * Опасное действие.
     */
    public function isDanger(): bool
    {
        return $this->danger;
    }

    /**
     * Есть кнопка у одной записи.
     */
    public function isSingle(): bool
    {
        return $this->single;
    }

    /**
     * Есть кнопка для выбранных записей.
     */
    public function isBulk(): bool
    {
        return $this->bulk;
    }

    /**
     * Способность Policy, которую проверяет действие.
     */
    public function getAbility(): string
    {
        return $this->ability;
    }

    /**
     * Действие можно запустить через MCP-сервер.
     */
    public function isAvailableInMcp(): bool
    {
        return $this->mcp;
    }

    /**
     * Настройки кнопки для браузера: подтверждение, подпись и цвет кнопки окна, есть ли мини-форма.
     *
     * @return array{confirm: ?string, button: string, danger: bool, form: bool}
     */
    public function clientOptions(): array
    {
        return ['confirm' => $this->getConfirm(), 'button' => $this->getLabel(), 'danger' => $this->isDanger(), 'form' => $this->hasForm()];
    }

    /**
     * Действие над записями корзины (восстановить, удалить навсегда), а не над обычными.
     */
    public function isForTrash(): bool
    {
        return false;
    }

    /**
     * Привязать к ресурсу.
     *
     * @internal вызывает `Resource::listActions()`
     */
    public function forResource(Resource $resource): static
    {
        $this->resource = $resource;
        $this->schema = null;

        return $this;
    }

    /**
     * Админ может выполнить действие над записью (способность `$ability` по Policy модели).
     */
    public function allows(Model $record): bool
    {
        return Authorization::allows($this->ability, $this->resource(), $record);
    }

    /**
     * Мини-форма: поля привязаны к модели раздела, правила и имена — под ключом `data`.
     *
     * @throws LogicException в мини-форме поле, которое там не работает
     */
    public function schema(): FormSchema
    {
        if ($this->schema instanceof FormSchema) {
            return $this->schema;
        }

        $schema = new FormSchema($this->fields(), $this->resource()::model());

        foreach ($schema->fields() as $field) {
            $field->nestedIn('data.');

            if ($field instanceof Nested || $field instanceof Repeater || $field instanceof Blocks || $field instanceof KeyValue || $field instanceof Seo || ($field instanceof SearchesOptions && ! $field instanceof Select) || $field->getDependsOn() !== null || $field instanceof Slug || $field instanceof Password || $field instanceof Gallery || $field instanceof RichText || $field->isTranslatable()) {
                throw new LogicException(__('fastleo::exceptions.action_field', ['field' => $field->name, 'action' => static::class, 'type' => class_basename($field)], Fastleo::LOCALE));
            }
        }

        return $this->schema = $schema;
    }

    /**
     * Есть мини-форма.
     */
    public function hasForm(): bool
    {
        return $this->fields() !== [];
    }

    /**
     * Ресурс действия.
     *
     * @throws LogicException действие не привязано к ресурсу
     */
    protected function resource(): Resource
    {
        return $this->resource ?? throw new LogicException(__('fastleo::exceptions.action_unbound', ['action' => static::class], Fastleo::LOCALE));
    }
}
