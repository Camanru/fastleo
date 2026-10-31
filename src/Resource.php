<?php

declare(strict_types=1);

namespace Fastleo;

use Fastleo\Actions\Action;
use Fastleo\Actions\DeleteRecords;
use Fastleo\Actions\ForceDeleteRecords;
use Fastleo\Actions\RestoreRecords;
use Fastleo\Activity\ActivityRecorder;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Field;
use Fastleo\Filters\Filter;
use Fastleo\Layout\Layout;
use Fastleo\Support\Authorization;
use Fastleo\Support\FormSchema;
use Illuminate\Contracts\Support\Htmlable;
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

    /** Подпись кнопки создания в списке («Добавить заявку»); null — «Создать». */
    protected static ?string $createLabel = null;

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
     * Варианты «На странице» под списком; null — из config('fastleo.list.per_page_options'), `[]` — выбора нет.
     *
     * @var list<int>|null
     */
    protected static ?array $perPageOptions = null;

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

    /** Панель «История изменений» в боковой колонке карточки записи (последние изменения; полная история — по ссылке). */
    protected static bool $historyPanel = false;

    /** Записи раздела находит глобальный поиск (Ctrl+K); `false` — раздел в нём не участвует. */
    protected static bool $globallySearchable = true;

    /** Раздел доступен ИИ-агентам через MCP-сервер (`fastleo:mcp`); `false` — сервер его не видит. */
    protected static bool $mcp = true;

    /** Раз в сколько секунд обновлять список и связанные таблицы раздела без перезагрузки; null — не обновлять. */
    protected static ?int $poll = null;

    /** Поля, разложенные из fields() (считаются один раз на объект). */
    private ?FormSchema $schema = null;

    /**
     * Фильтры списка, привязанные к ресурсу (по имени).
     *
     * @var array<string, Filter>|null
     */
    private ?array $filters = null;

    /** @var array<string, Related>|null */
    private ?array $relations = null;

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
     * Связанные таблицы на странице записи (просмотр и правка), под формой: записи других разделов, чей внешний
     * ключ ведёт на эту запись. Не обязательно.
     *
     * ```php
     * public function relations(): array
     * {
     *     return [Related::make('cars', CarResource::class), Related::make('offers', OfferResource::class)->label('Заявки')];
     * }
     * ```
     *
     * @return list<Related>
     */
    public function relations(): array
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
     * Название записи: заголовок формы и просмотра, хлебные крошки, подпись ветки дерева и результат поиска ⌘K.
     * По умолчанию — значение первого столбца списка, который не является связью (текстом, без тегов; у многоязычного
     * поля — первый язык): у позиции заявки заголовком станет её название, а не номер заявки-родителя. Если связей нет
     * или все столбцы — связи, берётся первый столбец. Первым в `fields()` ставят поле с названием, а не `Select` или
     * `Image`. Другое название — переопределить:
     *
     * ```php
     * public function title(Model $record): string
     * {
     *     return $record->name;
     * }
     * ```
     *
     * Варианты `BelongsTo` (`titleColumn()`) и журнал изменений берут название сами и от этого метода не зависят.
     */
    public function title(Model $record): string
    {
        $columns = $this->listFields();
        $field = array_find($columns, fn (Field $column): bool => ! $column instanceof BelongsTo && ! $column instanceof BelongsToMany) ?? $columns[0] ?? null;

        if (! $field instanceof Field) {
            return '';
        }

        $value = $field->display($field->shownRecord($record));
        $text = $value instanceof Htmlable ? strip_tags($value->toHtml()) : (string) $value;

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text)));
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
     * В боковой колонке карточки записи — панель «История изменений» (`protected static bool $historyPanel = true;`).
     * Нужен журнал изменений раздела (`$activity`); показывается тем, кто может смотреть журнал и запись.
     */
    public static function hasHistoryPanel(): bool
    {
        return static::$historyPanel && static::$activity;
    }

    /**
     * В боковой колонке карточки этой записи есть панель истории: она включена, журнал работает, администратор
     * может смотреть журнал раздела и саму запись.
     */
    public function showsHistoryPanel(Model $record): bool
    {
        return static::hasHistoryPanel() && ActivityRecorder::canView() && Authorization::allows('viewAny', $this) && Authorization::allows('view', $this, $record);
    }

    /**
     * В карточке записи есть боковая колонка: панель истории или таблицы `Related::aside()`, доступные администратору.
     */
    public function hasSideColumn(Model $record): bool
    {
        if ($this->showsHistoryPanel($record)) {
            return true;
        }

        return $this->showsRelations($record) && array_any($this->listRelations(), fn (Related $related): bool => $related->isAside() && Authorization::allows('view', $this, $record) && Authorization::allows('viewAny', $related->child()));
    }

    /**
     * На странице записи показываются связанные таблицы (`relations()`): запись не в корзине — таблица строится по живому родителю.
     */
    public function showsRelations(Model $record): bool
    {
        return ! method_exists($record, 'trashed') || ! $record->trashed();
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
     * Период автообновления списка в секундах (от 5 до 3600) или null — список обновляется только перезагрузкой.
     *
     * ```php
     * protected static ?int $poll = 10; // входящие заявки появляются сами
     * ```
     */
    public static function poll(): ?int
    {
        return static::$poll === null ? null : max(5, min(3600, static::$poll));
    }

    /**
     * Раздел доступен через MCP-сервер: открыт, пока не задан `protected static bool $mcp = false`.
     * Статический: работают и `DoctorResource::isAvailableInMcp()`, и `$resource->isAvailableInMcp()`.
     */
    public static function isAvailableInMcp(): bool
    {
        return static::$mcp;
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
     * Подпись кнопки создания в списке.
     */
    public static function createLabel(): string
    {
        return static::$createLabel ?? __('fastleo::resources.create');
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

    /** Тона подсветки строки (`rowTone()`). */
    public const array ROW_TONES = ['success', 'warning', 'danger', 'info', 'neutral'];

    /**
     * Цвет фона строки списка и связанной таблицы: `success`, `warning`, `danger`, `info`, `neutral` или null (без подсветки).
     * Подсветка — только подсказка глазу: смысл должен быть виден и в самих столбцах (статус текстом, `badges()`).
     *
     * ```php
     * public function rowTone(Model $record): ?string
     * {
     *     return match ($record->status) {
     *         Status::Done => 'success',
     *         Status::Overdue => 'danger',
     *         default => null,
     *     };
     * }
     * ```
     */
    public function rowTone(Model $record): ?string
    {
        return null;
    }

    /**
     * Тон строки, проверенный: опечатка в `rowTone()` не остаётся незамеченной.
     *
     * @throws LogicException тон не из списка `ROW_TONES`
     */
    final public function rowToneFor(Model $record): ?string
    {
        $tone = $this->rowTone($record);

        if ($tone !== null && ! in_array($tone, self::ROW_TONES, true)) {
            throw new LogicException(__('fastleo::exceptions.row_tone', ['class' => static::class, 'tone' => $tone, 'tones' => implode(', ', self::ROW_TONES)], Fastleo::LOCALE));
        }

        return $tone;
    }

    /**
     * Варианты числа записей на странице для выбора под списком: числа из `$perPageOptions` (или `fastleo.list.per_page_options`)
     * в пределах `fastleo.list.max_all`, плюс обычное число записей раздела; по возрастанию. Пусто — выбора нет.
     *
     * @return list<int>
     */
    public static function perPageOptions(): array
    {
        $configured = config('fastleo.list.per_page_options');
        $given = static::$perPageOptions ?? (is_array($configured) ? $configured : []);
        $max = max(1, config()->integer('fastleo.list.max_all', 1000));
        $sizes = array_values(array_filter(array_map(intval(...), array_filter($given, is_numeric(...))), fn (int $size): bool => $size > 0 && $size <= $max));

        if ($sizes === []) {
            return [];
        }

        $sizes[] = static::perPage();
        sort($sizes);

        return array_values(array_unique($sizes));
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
     * Связанные таблицы, привязанные к ресурсу, по имени связи.
     *
     * @return array<string, Related>
     *
     * @throws LogicException две таблицы с одной связью, связь не `HasMany`, раздел не найден, связь ещё и поле формы, раскрываемая таблица без вложенных
     */
    public function listRelations(): array
    {
        if ($this->relations !== null) {
            return $this->relations;
        }

        $relations = [];

        foreach ($this->relations() as $related) {
            if (isset($relations[$related->name])) {
                throw new LogicException(__('fastleo::exceptions.related_duplicate', ['relation' => $related->name, 'resource' => static::class], Fastleo::LOCALE));
            }

            $relations[$related->name] = $related->bind($this);

            // Раскрывать нечего: у дочернего раздела нет своих связанных таблиц.
            if ($related->isExpandable() && $related->child()->relations() === []) {
                throw new LogicException(__('fastleo::exceptions.related_expandable', ['relation' => $related->name, 'class' => $related->resource], Fastleo::LOCALE));
            }
        }

        return $this->relations = $relations;
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
