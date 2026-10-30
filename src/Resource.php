<?php

declare(strict_types=1);

namespace Fastleo;

use Fastleo\Actions\Action;
use Fastleo\Actions\DeleteRecords;
use Fastleo\Actions\ForceDeleteRecords;
use Fastleo\Actions\RestoreRecords;
use Fastleo\Fields\Field;
use Fastleo\Filters\Filter;
use Fastleo\Layout\Layout;
use Fastleo\Support\Authorization;
use Fastleo\Support\FormSchema;
use Fastleo\Support\PersonalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use LogicException;

/**
 * Раздел админки: модель, подписи, меню и поля. Один класс описывает список, форму, просмотр и права.
 *
 * Классы лежат в `app/Fastleo` сайта и подхватываются сами:
 *
 * ```php
 * final class DoctorResource extends Resource
 * {
 *     protected static string $model = Doctor::class;
 *     protected static ?string $label = 'Врачи';
 *     protected static ?string $singular = 'Врач';
 *     protected static string $icon = 'stethoscope';
 *
 *     public function fields(): array
 *     {
 *         return [
 *             Text::make('name', 'Имя')->required()->searchable()->sortable(),
 *             Toggle::make('is_active', 'Показывать на сайте'),
 *         ];
 *     }
 * }
 * ```
 */
abstract class Resource
{
    /**
     * Модель Eloquent раздела.
     *
     * @var class-string<Model>
     */
    protected static string $model;

    /** Заголовок раздела и пункт меню («Врачи»). По умолчанию — имя модели. */
    protected static ?string $label = null;

    /** Название одной записи («Врач»): кнопка «Создать», заголовки форм. */
    protected static ?string $singular = null;

    /** Пояснение под заголовком списка («Новости и материалы блога»). По умолчанию нет. */
    protected static ?string $description = null;

    /** Иконка Lucide в меню: https://lucide.dev/icons. */
    protected static string $icon = 'file-text';

    /** Группа меню («Контент») или null — без группы. */
    protected static ?string $group = null;

    /** Позиция в меню внутри группы: меньше — выше. */
    protected static int $position = 0;

    /** Часть адреса: /fastleo/{slug}. По умолчанию — имя модели во множественном числе (doctors). */
    protected static ?string $slug = null;

    /** Записей на странице списка; null — из config('fastleo.list.per_page'). */
    protected static ?int $perPage = null;

    /**
     * Порядок списка по умолчанию: [колонка, 'asc'|'desc']; null — новые записи сверху.
     *
     * @var array{0: string, 1: 'asc'|'desc'}|null
     */
    protected static ?array $defaultOrder = null;

    /** Только просмотр: нет создания, редактирования и удаления. */
    protected static bool $readonly = false;

    /**
     * Колонка ручного порядка записей (`sort`): стрелки и перетаскивание в списке, новая запись — в конец.
     * Пока список не отсортирован по столбцу и нет поиска, записи идут по этой колонке.
     */
    protected static ?string $sortColumn = null;

    /**
     * Колонка родителя (`parent_id`): список показывается деревом. Для выбора родителя в форме
     * добавьте поле `BelongsTo::make('parent_id')` (связь `parent()` на ту же модель).
     */
    protected static ?string $treeColumn = null;

    /** Кнопка «Экспорт» в списке: выгрузка в xlsx/csv ([import-export.md](docs/import-export.md)). */
    protected static bool $exportable = true;

    /** Кнопка «Импорт» в списке: загрузка записей из xlsx/csv ([import-export.md](docs/import-export.md)). */
    protected static bool $importable = false;

    /**
     * Колонка, по которой строка файла находит запись для обновления; null — ключ модели (`id`).
     * Запись по своему ключу (`'sku'`) не найдена — создаётся; по `id` — строка пропускается.
     */
    protected static ?string $importKey = null;

    /** Вести журнал изменений раздела («кто, когда, было → стало», откат). */
    protected static bool $activity = true;

    /** Записи раздела находит глобальный поиск (Ctrl+K); `false` — раздел в нём не участвует. */
    protected static bool $globallySearchable = true;

    /** Раздел доступен ИИ-агентам через MCP-сервер (`fastleo:mcp`); `false` — сервер его не видит. */
    protected static bool $mcp = true;

    /**
     * Открыть через MCP раздел с данными людей (поля `Email`, `Tel`, колонки `email`, `phone`…). По умолчанию такой
     * раздел сервер не видит, даже если `$mcp = true`; `true` — разрешить явно.
     */
    protected static bool $mcpPersonalData = false;

    /** Поля, разложенные из fields() (считаются один раз на объект). */
    private ?FormSchema $schema = null;

    /**
     * Фильтры списка, привязанные к ресурсу (по имени).
     *
     * @var array<string, Filter>|null
     */
    private ?array $filters = null;

    /**
     * Действия, привязанные к ресурсу (по ключу), вместе со встроенными.
     *
     * @var array<string, Action>|null
     */
    private ?array $actions = null;

    /**
     * Поля и раскладка формы: поля (`Text::make(...)`) и контейнеры (`Tabs`, `Section`, `Grid`, `Aside`).
     *
     * @return list<Field|Layout>
     */
    abstract public function fields(): array;

    /**
     * Фильтры списка: панель над таблицей, значения в адресе `?filter[имя]=…`.
     *
     * ```php
     * public function filters(): array
     * {
     *     return [
     *         SelectFilter::make('status'),
     *         BelongsToFilter::make('category_id'),
     *         BooleanFilter::make('is_published', 'Опубликовано'),
     *         DateRangeFilter::make('published_on', 'Дата'),
     *     ];
     * }
     * ```
     *
     * @return list<Filter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Действия над записью и над выбранными в списке (кроме встроенных «Удалить», «Восстановить»,
     * «Удалить навсегда» — они есть всегда) — [actions.md](docs/actions.md).
     *
     * ```php
     * public function actions(): array
     * {
     *     return [new PublishArticles, new SendNewsletter];
     * }
     * ```
     *
     * @return list<Action>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Число в меню рядом с разделом (например, новые заявки) или null.
     *
     * ```php
     * public static function badge(): ?int
     * {
     *     return Request::query()->where('is_new', true)->count() ?: null;
     * }
     * ```
     */
    public static function badge(): ?int
    {
        return null;
    }

    /**
     * Адрес записи на сайте — кнопка «Открыть на сайте». null — кнопки нет.
     *
     * ```php
     * public function url(Model $record): ?string
     * {
     *     return route('doctors.show', $record);
     * }
     * ```
     */
    public function url(Model $record): ?string
    {
        return null;
    }

    /**
     * Какие записи раздела попадают в `/sitemap.xml`. null (по умолчанию) — раздела в карте нет.
     * Адрес записи берётся из `url()`, дата изменения — из `updated_at`; записи с запретом индексации
     * в поле [Seo](docs/fields/seo.md) пропускаются.
     *
     * ```php
     * public function sitemap(Builder $query): ?Builder
     * {
     *     return $query->where('is_published', true);
     * }
     * ```
     *
     * @param  Builder<Model>  $query  записи модели без ограничения `query()` (оно — для админки)
     * @return Builder<Model>|null
     */
    public function sitemap(Builder $query): ?Builder
    {
        return null;
    }

    /**
     * Ограничение выборки: какие записи видны в разделе (список, просмотр, изменение, удаление).
     *
     * ```php
     * public function query(Builder $query): Builder
     * {
     *     return $query->where('type', 'news');
     * }
     * ```
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function query(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Дополнительное ограничение раздела поверх Policy модели: `false` запрещает действие
     * (кнопки скрываются, запрос получает 403, массовое действие пропускает запись).
     * Вызывается для способностей Policy (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`)
     * и для своих методов Policy раздела (`export`, `import`, `reorder`);
     * `$record` — null для действий над разделом (список, создание).
     *
     * ```php
     * public function can(string $ability, ?Model $record): bool
     * {
     *     return $ability !== 'delete' || ! $record?->is_system;
     * }
     * ```
     */
    public function can(string $ability, ?Model $record): bool
    {
        return true;
    }

    /**
     * Класс модели.
     *
     * @return class-string<Model>
     */
    public static function model(): string
    {
        return static::$model;
    }

    /**
     * Раздел ведёт журнал изменений.
     */
    public static function logsActivity(): bool
    {
        return static::$activity;
    }

    /**
     * Записи раздела находит глобальный поиск.
     */
    public static function isGloballySearchable(): bool
    {
        return static::$globallySearchable;
    }

    /**
     * Раздел виден в меню (по умолчанию — всегда). Адрес раздела работает и без пункта меню.
     *
     * ```php
     * public static function inMenu(): bool
     * {
     *     return config()->boolean('shop.enabled');
     * }
     * ```
     */
    public static function inMenu(): bool
    {
        return true;
    }

    /**
     * Раздел доступен через MCP-сервер. Статический, как в 4.0.0: работают и `DoctorResource::isAvailableInMcp()`,
     * и `$resource->isAvailableInMcp()`.
     */
    public static function isAvailableInMcp(): bool
    {
        if (! static::$mcp) {
            return false;
        }

        $resource = resolve(static::class);

        return $resource instanceof static && $resource->mcpPersonalField() === null;
    }

    /**
     * Поле с данными людей, из-за которого раздел закрыт от MCP-сервера (`phone`, `blocks.email`); null — таких полей
     * нет, раздел закрыт или открыт явно (`$mcp`, `$mcpPersonalData`).
     */
    public function mcpPersonalField(): ?string
    {
        return static::$mcp && ! static::$mcpPersonalData ? PersonalData::find($this->schema()->fields()) : null;
    }

    /**
     * Модель задана.
     */
    public static function hasModel(): bool
    {
        return isset(static::$model);
    }

    /**
     * Заголовок раздела.
     */
    public static function label(): string
    {
        return static::$label ?? Str::headline(Str::pluralStudly(class_basename(static::model())));
    }

    /**
     * Название одной записи.
     */
    public static function singular(): string
    {
        return static::$singular ?? Str::headline(class_basename(static::model()));
    }

    /**
     * Пояснение под заголовком списка или null.
     */
    public static function description(): ?string
    {
        return static::$description;
    }

    /**
     * Иконка в меню.
     */
    public static function icon(): string
    {
        return static::$icon;
    }

    /**
     * Группа меню или null.
     */
    public static function group(): ?string
    {
        return static::$group;
    }

    /**
     * Позиция в меню.
     */
    public static function position(): int
    {
        return static::$position;
    }

    /**
     * Часть адреса раздела.
     *
     * ```php
     * DoctorResource::slug(); // 'doctors'
     * ```
     */
    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(Str::pluralStudly(class_basename(static::model())));
    }

    /**
     * Записей на странице списка.
     */
    public static function perPage(): int
    {
        return static::$perPage ?? max(1, config()->integer('fastleo.list.per_page', 25));
    }

    /**
     * Порядок списка по умолчанию или null.
     *
     * @return array{0: string, 1: 'asc'|'desc'}|null
     */
    public static function defaultOrder(): ?array
    {
        return static::$defaultOrder;
    }

    /**
     * Колонка ручного порядка или null.
     */
    public static function sortColumn(): ?string
    {
        return static::$sortColumn;
    }

    /**
     * Колонка родителя дерева или null.
     */
    public static function treeColumn(): ?string
    {
        return static::$treeColumn;
    }

    /**
     * Раздел только для просмотра.
     */
    public static function isReadonly(): bool
    {
        return static::$readonly;
    }

    /**
     * Раздел можно выгрузить в Excel/CSV.
     */
    public static function isExportable(): bool
    {
        return static::$exportable;
    }

    /**
     * В раздел можно загружать записи из файла (раздел «только просмотр» — нельзя).
     */
    public static function isImportable(): bool
    {
        return static::$importable && ! static::isReadonly();
    }

    /**
     * Своя колонка сопоставления строк файла с записями или null — ключ модели.
     */
    public static function importKey(): ?string
    {
        return static::$importKey;
    }

    /**
     * У модели есть корзина (SoftDeletes).
     */
    public static function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(static::model()), true);
    }

    /**
     * Новый пустой экземпляр модели.
     */
    public function newModel(): Model
    {
        $model = static::model();

        return new $model;
    }

    /**
     * Запрос записей раздела с ограничением `query()`.
     *
     * @return Builder<Model>
     */
    public function newQuery(): Builder
    {
        return $this->query($this->newModel()->newQuery());
    }

    /**
     * Описание формы: поля и раскладка.
     */
    public function schema(): FormSchema
    {
        return $this->schema ??= new FormSchema($this->fields(), static::model());
    }

    /**
     * Фильтры списка, привязанные к ресурсу, по имени.
     *
     * @return array<string, Filter>
     *
     * @throws LogicException два фильтра с одним именем
     */
    public function listFilters(): array
    {
        if ($this->filters !== null) {
            return $this->filters;
        }

        $filters = [];

        foreach ($this->filters() as $filter) {
            if (isset($filters[$filter->name])) {
                throw new LogicException(__('fastleo::exceptions.filter_duplicate', ['filter' => $filter->name, 'resource' => static::class], Fastleo::LOCALE));
            }

            $filters[$filter->name] = $filter->forResource($this);
        }

        return $this->filters = $filters;
    }

    /**
     * Все действия раздела по ключу: встроенные (удалить; в корзине — восстановить, удалить навсегда) и `actions()`.
     *
     * @return array<string, Action>
     *
     * @throws LogicException два действия с одним ключом
     */
    public function listActions(): array
    {
        if ($this->actions !== null) {
            return $this->actions;
        }

        $actions = [];
        $builtIn = static::usesSoftDeletes() ? [new DeleteRecords, new RestoreRecords, new ForceDeleteRecords] : [new DeleteRecords];

        foreach ([...$builtIn, ...$this->actions()] as $action) {
            $key = $action->key();

            if (isset($actions[$key]) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) !== 1) {
                throw new LogicException(__('fastleo::exceptions.action_key', ['action' => $action::class, 'key' => $key, 'resource' => static::class], Fastleo::LOCALE));
            }

            $actions[$key] = $action->forResource($this);
        }

        return $this->actions = $actions;
    }

    /**
     * Действия для списка (`$trashed` — корзины) или для одной записи.
     *
     * ```php
     * $resource->actionsFor(bulk: true, trashed: false); // кнопки полосы выбранных записей
     * ```
     *
     * @return array<string, Action>
     */
    public function actionsFor(bool $bulk, bool $trashed): array
    {
        return array_filter($this->listActions(), fn (Action $action): bool => $action->isForTrash() === $trashed
            && ($bulk ? $action->isBulk() : $action->isSingle())
            && (! static::isReadonly() || ! in_array($action->getAbility(), Authorization::WRITE_ABILITIES, true)));
    }

    /**
     * У записи дерева есть вложенные (такую нельзя удалить). При удалении навсегда (`$forever`)
     * учитываются и вложенные из корзины: иначе они остались бы без родителя.
     */
    public function hasChildren(Model $record, bool $forever = false): bool
    {
        $tree = static::treeColumn();

        if ($tree === null) {
            return false;
        }

        $query = $this->newModel()->newQuery();

        if ($forever && static::usesSoftDeletes()) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->where($tree, $record->getKey())->exists();
    }

    /**
     * Поля, видимые в форме при создании (null) или редактировании записи.
     *
     * @return list<Field>
     */
    public function formFields(?Model $record): array
    {
        return array_values(array_filter($this->schema()->fields(), fn (Field $field): bool => $field->isVisibleInForm($record)));
    }

    /**
     * Поля, которые форма записывает в модель: видимые и не только для чтения.
     *
     * @return list<Field>
     */
    public function writableFields(?Model $record): array
    {
        return array_values(array_filter($this->formFields($record), fn (Field $field): bool => $field->isWritable()));
    }

    /**
     * Столбцы списка.
     *
     * @return list<Field>
     */
    public function listFields(): array
    {
        return array_values(array_filter($this->schema()->fields(), fn (Field $field): bool => $field->isVisibleInList()));
    }

    /**
     * Поля страницы просмотра.
     *
     * @return list<Field>
     */
    public function showFields(Model $record): array
    {
        return array_values(array_filter($this->schema()->fields(), fn (Field $field): bool => $field->isVisibleOnShow($record)));
    }

    /**
     * Значение управляющего поля (`visibleWhen`, `dependsOn`) в виде формы. Из ввода — только если
     * админ может менять это поле; иначе сохранённое значение записи (при создании — значение
     * по умолчанию), чтобы подделка ввода не открывала чужие варианты.
     *
     * ```php
     * $resource->controllingValue('category_id', $record, session()->getOldInput()); // '5'
     * ```
     *
     * @param  array<array-key, mixed>|null  $input  ввод формы; null — форма открыта впервые
     */
    public function controllingValue(string $name, ?Model $record, ?array $input): mixed
    {
        $field = $this->schema()->field($name);

        if ($input !== null && $field instanceof Field && in_array($field, $this->writableFields($record), true)) {
            return Field::dependencyValue($input[$name] ?? null);
        }

        return Field::dependencyValue($record instanceof Model && $record->exists ? $record->getAttribute($name) : $field?->formValue(null));
    }

    /**
     * Проверить, что модель разрешает записывать все поля формы (`$fillable`/`$guarded`).
     * Ресурс и модель не должны расходиться: иначе значение молча не сохранилось бы.
     *
     * @param  list<Field>  $fields
     *
     * @throws LogicException поле ресурса не разрешено моделью
     */
    public function assertFillable(Model $record, array $fields): void
    {
        foreach ($fields as $field) {
            if ($field->writesAttribute() && ! $record->isFillable($field->name)) {
                throw new LogicException(__('fastleo::exceptions.field_not_fillable', [
                    'field' => $field->name,
                    'resource' => static::class,
                    'model' => $record::class,
                ], Fastleo::LOCALE));
            }
        }
    }

    /**
     * Правила валидации записываемых полей.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(?Model $record): array
    {
        $rules = [];

        foreach ($this->writableFields($record) as $field) {
            $rules = [...$rules, ...$field->getRules($record)];
        }

        return $rules;
    }

    /**
     * Подписи полей для текстов ошибок («Поле «Заголовок» обязательно»).
     *
     * @return array<string, string>
     */
    public function validationAttributes(?Model $record): array
    {
        $attributes = [];

        foreach ($this->writableFields($record) as $field) {
            $attributes = [...$attributes, ...$field->validationAttributes()];
        }

        return $attributes;
    }
}
