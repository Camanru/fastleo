<?php

declare(strict_types=1);

namespace Fastleo;

use Closure;
use Fastleo\Actions\Action;
use Fastleo\Actions\DeleteRecords;
use Fastleo\Actions\ForceDeleteRecords;
use Fastleo\Actions\MarkRead;
use Fastleo\Actions\RestoreRecords;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Field;
use Fastleo\Fields\Image;
use Fastleo\Fields\MorphTo;
use Fastleo\Filters\Filter;
use Fastleo\Layout\Layout;
use Fastleo\Support\Authorization;
use Fastleo\Support\FormSchema;
use Fastleo\Support\OperationCancelled;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use LogicException;

/**
 * Раздел админки: модель, подписи, меню и поля. Один класс описывает список, форму, просмотр и права.
 *
 * Классы лежат в `app/Fastleo` сайта и подхватываются сами:
 *
 * ```php
 * final class ProductResource extends Resource
 * {
 *     protected static string $model = Product::class;
 *     protected static ?string $label = 'Товары';
 *     protected static ?string $singular = 'Товар';
 *     protected static string $icon = 'shopping-bag';
 *
 *     public function fields(): array
 *     {
 *         return [
 *             Text::make('name', 'Название')->required()->searchable()->sortable(),
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

    /** Заголовок раздела и пункт меню («Товары»). По умолчанию — имя модели. */
    protected static ?string $label = null;

    /** Название одной записи («Товар»): кнопка «Создать», заголовки форм. */
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

    /** Часть адреса: /fastleo/{slug}. По умолчанию — имя модели во множественном числе (products). */
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

    /** Кнопка «Экспорт» в списке: выгрузка в csv ([import-export.md](docs/import-export.md)). */
    protected static bool $exportable = true;

    /** Кнопка «Импорт» в списке: загрузка записей из csv ([import-export.md](docs/import-export.md)). */
    protected static bool $importable = false;

    /**
     * Колонка, по которой строка файла находит запись для обновления; null — ключ модели (`id`).
     * Запись по своему ключу (`'sku'`) не найдена — создаётся; по `id` — строка пропускается.
     */
    protected static ?string $importKey = null;

    /** Записи раздела находит глобальный поиск (Ctrl+K); `false` — раздел в нём не участвует. */
    protected static bool $globallySearchable = true;

    /** Раздел доступен ИИ-агентам через MCP-сервер (`fastleo:mcp`); `false` — сервер его не видит. */
    protected static bool $mcp = true;

    /** Раз в сколько секунд обновлять список и связанные таблицы раздела без перезагрузки; null — не обновлять. */
    protected static ?int $poll = null;

    /**
     * Колонка отметки «просмотрено» (`timestamp`, nullable): открытие записи ставит время, число в меню считает
     * непросмотренные, их строки в списке выделены. null — отметки нет.
     */
    protected static ?string $readColumn = null;

    /**
     * Открытие записи ставит отметку `$readColumn` (по умолчанию да). `false` — отметка меняется только действием
     * «Просмотрено» / «Не просмотрено» или кодом сайта (`markRead()`): так «прочитано» значит «взяли в работу», а не «кто-то открыл».
     */
    protected static bool $readOnOpen = true;

    /**
     * Фильтры списка применяются сами при выборе значения (по умолчанию да). `false` — только кнопкой «Фильтровать»,
     * которая стоит в одном ряду с полями: можно спокойно выставить несколько значений и даты, страница не перезагружается.
     */
    protected static bool $filtersApplyOnChange = true;

    /**
     * Панель фильтров в списке свёрнута, пока её не откроют, даже если фильтры применены или заданы по умолчанию
     * (число применённых — на кнопке «Фильтры»). Открытое или закрытое состояние помнится на время вкладки браузера.
     */
    protected static bool $filtersCollapsed = false;

    /** Поля, разложенные из fields() (считаются один раз на объект). */
    private ?FormSchema $schema = null;

    /**
     * Фильтры списка, привязанные к ресурсу (по имени).
     *
     * @var array<string, Filter>|null
     */
    private ?array $filters = null;

    /** @var array<string, Filter>|null фильтры, видимые администратору (после `hidden(fn …)`), на время запроса */
    private ?array $visibleFilters = null;

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
     * ключ ведёт на эту запись. Не обязательно. Таблицу можно положить и во вкладку формы: `Tab::make('Отзывы', [Related::make(...)])`
     * в `fields()` — тогда она показывается во вкладке, а не под формой.
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
     * Число в меню рядом с разделом (например, новые заявки) или null. С `$readColumn` по умолчанию — число
     * непросмотренных записей (с ограничением `query()` раздела).
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
        $column = static::readColumn();

        if ($column === null) {
            return null;
        }

        /** @var static $resource */
        $resource = resolve(static::class);
        $query = $resource->newQuery();

        // Строки списка, а не размер первой группы, если `query()` раздела группирует.
        return $query->whereNull($query->qualifyColumn($column))->toBase()->getCountForPagination() ?: null;
    }

    /**
     * Колонка отметки «просмотрено» или null.
     *
     * ```php
     * protected static ?string $readColumn = 'read_at'; // $table->timestamp('read_at')->nullable();
     * ```
     */
    public static function readColumn(): ?string
    {
        return static::$readColumn;
    }

    /**
     * Открытие записи ставит отметку «просмотрено» (`$readOnOpen`).
     */
    public static function readOnOpen(): bool
    {
        return static::$readOnOpen;
    }

    /**
     * Фильтры списка применяются при выборе значения (`$filtersApplyOnChange`); иначе — кнопкой «Фильтровать».
     */
    public static function filtersApplyOnChange(): bool
    {
        return static::$filtersApplyOnChange;
    }

    /**
     * Панель фильтров свёрнута, пока её не откроют (`$filtersCollapsed`).
     */
    public static function filtersCollapsed(): bool
    {
        return static::$filtersCollapsed;
    }

    /**
     * Запись ещё не просматривали (у раздела есть `$readColumn`, а отметки нет). Запись, выбранная без этой колонки
     * (`query()` раздела с `select()`), непросмотренной не считается.
     */
    public function isUnread(Model $record): bool
    {
        $column = static::readColumn();

        return $column !== null && array_key_exists($column, $record->getAttributes()) && $record->getAttribute($column) === null;
    }

    /**
     * Отметить записи просмотренными (`true`) или нет: прямым запросом, без событий модели
     * и смены `updated_at` — отметка не меняет саму запись. Без `$readColumn` ничего не делает.
     *
     * ```php
     * $resource->markRead([$lead->getKey()], read: true);
     * ```
     *
     * @param  list<mixed>  $keys  ключи записей
     * @return int сколько записей изменилось
     */
    public function markRead(array $keys, bool $read = true): int
    {
        $column = static::readColumn();

        if ($column === null || $keys === []) {
            return 0;
        }

        $model = $this->newModel();

        // Без глобальных scope модели: ключи — записи, которые раздел уже нашёл через свой query() (в том числе из корзины).
        return $model->newQueryWithoutScopes()->toBase()
            ->whereIn($model->getQualifiedKeyName(), $keys)
            ->when($read, fn (QueryBuilder $query): QueryBuilder => $query->whereNull($model->qualifyColumn($column)), fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull($model->qualifyColumn($column)))
            ->update([$column => $read ? $model->freshTimestampString() : null]);
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
     * Варианты `BelongsTo` (`titleColumn()`) берут название сами и от этого метода не зависят.
     */
    public function title(Model $record): string
    {
        $columns = $this->listFields();
        $field = array_find($columns, fn (Field $column): bool => ! $column instanceof BelongsTo && ! $column instanceof BelongsToMany && ! $column instanceof MorphTo) ?? $columns[0] ?? null;

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
     *     return route('products.show', $record);
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
     * Проверка перед удалением записи (из раздела, из корзины, массовым действием и из MCP): выполняется внутри транзакции
     * удаления. Бросьте `OperationCancelled` с причиной — запись останется, админ увидит причину.
     *
     * ```php
     * public function beforeDelete(Model $record): void
     * {
     *     if ($record->orders()->exists()) {
     *         throw new OperationCancelled('У записи есть заказы.');
     *     }
     * }
     * ```
     *
     * @throws OperationCancelled
     */
    public function beforeDelete(Model $record): void
    {
        //
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
     * В карточке записи есть боковая колонка: таблицы `Related::aside()`, доступные администратору.
     */
    public function hasSideColumn(Model $record): bool
    {
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
     * Адрес записи для списков и поиска: форма правки, если запись можно менять (и она не в корзине), иначе страница
     * просмотра (если она включена: `fastleo.record_view`, и можно `view`), иначе null.
     *
     * Список с сотнями строк передаёт `$can` — свою проверку прав с запоминанием ответов, чтобы не спрашивать Policy
     * по нескольку раз на строку.
     *
     * ```php
     * $resource->openUrl($article);                    // /fastleo/articles/5/edit
     * $resource->openUrl($article, ['return' => $back]);  // с адресом возврата
     * ```
     *
     * Права проверяются (в отличие от `Fastleo::recordUrl()` и `recordAddress()`), поэтому адреса может не быть.
     *
     * @param  array<string, mixed>  $query
     * @param  (Closure(string, Model): bool)|null  $can  проверка права (`'update'`, запись); по умолчанию — `Authorization`
     */
    public function openUrl(Model $record, array $query = [], bool $absolute = true, ?Closure $can = null): ?string
    {
        $can ??= fn (string $ability, Model $model): bool => Authorization::allows($ability, $this, $model);

        if (! $this->isTrashed($record) && $can('update', $record)) {
            return route('fastleo.resources.edit', [static::slug(), $record->getKey(), ...$this->cleanQuery($query)], $absolute);
        }

        return Fastleo::recordView() && $can('view', $record)
            ? route('fastleo.resources.show', [static::slug(), $record->getKey(), ...$this->cleanQuery($query)], $absolute)
            : null;
    }

    /**
     * Адрес записи для чтения (ссылки на связанную запись, уведомления, виджеты): страница просмотра, если она включена
     * и можно `view`; без неё — форма правки, если запись можно менять; иначе null.
     *
     * @param  array<string, mixed>  $query
     */
    public function viewUrl(Model $record, array $query = [], bool $absolute = true): ?string
    {
        if (Fastleo::recordView()) {
            return $this->showUrl($record, $query, $absolute);
        }

        return ! $this->isTrashed($record) && Authorization::allows('update', $this, $record)
            ? route('fastleo.resources.edit', [static::slug(), $record->getKey(), ...$this->cleanQuery($query)], $absolute)
            : null;
    }

    /**
     * Адрес записи для уведомлений, писем и ссылок сайта: страница просмотра, если она включена (`fastleo.record_view`),
     * иначе форма правки. Права не проверяются: ссылку строят там, где администратора нет (вебхук, очередь, команда),
     * а открывшаяся страница проверит права сама.
     *
     * ```php
     * $resource->recordAddress($lead);         // http://site.test/fastleo/leads/5
     * $resource->recordAddress($lead, false);  // /fastleo/leads/5
     * ```
     */
    public function recordAddress(Model $record, bool $absolute = true): string
    {
        return route(Fastleo::recordView() ? 'fastleo.resources.show' : 'fastleo.resources.edit', [static::slug(), $record->getKey()], $absolute);
    }

    /**
     * Адрес для окна (страница без меню в iframe) по адресу страницы просмотра из `viewUrl()`: null, если окно не
     * включено (`fastleo.record_view` не `'modal'`) или страницы нет. Права уже учтены в `viewUrl()`, Policy второй раз не зовётся.
     *
     * ```php
     * Resource::viewModalUrl($resource->viewUrl($article));   // http://site.test/fastleo/articles/5?frame=1
     * ```
     */
    public static function viewModalUrl(?string $viewUrl): ?string
    {
        return Fastleo::recordViewInModal() && $viewUrl !== null ? (string) Uri::of($viewUrl)->withQuery(['frame' => 1]) : null;
    }

    /**
     * Страница просмотра записи (null — выключена или нельзя `view`).
     *
     * @param  array<string, mixed>  $query
     */
    private function showUrl(Model $record, array $query, bool $absolute): ?string
    {
        return Fastleo::recordView() && Authorization::allows('view', $this, $record)
            ? route('fastleo.resources.show', [static::slug(), $record->getKey(), ...$this->cleanQuery($query)], $absolute)
            : null;
    }

    /**
     * Параметры адреса без пустых значений.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function cleanQuery(array $query): array
    {
        return array_filter($query, fn (mixed $value): bool => $value !== null);
    }

    /**
     * Запись в корзине.
     */
    private function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
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
     * Запись закрыта для ИИ-агента через MCP, пока человек её не откроет (по умолчанию нет): агент не правит её, не удаляет
     * и не запускает для неё действия. Агент не должен менять то, что человек уже согласовал: например, публикацию,
     * поставленную на отправку. Человек в админке работает как обычно.
     *
     * ```php
     * public function isLockedForMcp(Model $record): bool
     * {
     *     return $record->status === 'scheduled';
     * }
     * ```
     */
    public function isLockedForMcp(Model $record): bool
    {
        return false;
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
     * ProductResource::slug(); // 'products'
     * ```
     */
    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(Str::pluralStudly(class_basename(static::model())));
    }

    /** Ключи встроенных действий: заняты в любом разделе, даже если само действие в нём не появляется (нет корзины или `$readColumn`). */
    public const array RESERVED_ACTION_KEYS = ['delete', 'restore', 'force-delete', 'mark-read', 'mark-unread'];

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
     * Главная кнопка строки списка: заметная кнопка с подписью рядом со значками записи (остальные действия — в меню «…»).
     * Подпись может зависеть от записи. Нет действия с таким ключом или оно записи недоступно — кнопки нет.
     *
     * ```php
     * public function primaryRowAction(Model $record): ?array
     * {
     *     return $record->manager_id === null
     *         ? ['key' => (new TakeLead)->key(), 'label' => 'Взять в работу', 'primary' => true]
     *         : null;
     * }
     * ```
     *
     * @return array{key: string, label: string, primary?: bool}|null `primary` — залитая кнопка (по умолчанию контурная)
     */
    public function primaryRowAction(Model $record): ?array
    {
        return null;
    }

    /**
     * Карточка записи над вкладками связанных таблиц на странице редактирования: имя Blade-шаблона
     * (получает `$record` и `$resource`), только для чтения. null — карточки нет.
     *
     * ```php
     * public function summaryView(): ?string
     * {
     *     return 'clients.card'; // resources/views/clients/card.blade.php
     * }
     * ```
     */
    public function summaryView(): ?string
    {
        return null;
    }

    /**
     * Кнопки в шапке страницы редактирования записи рядом с «Удалить», например «Добавить заявку» клиенту
     * (ссылка на форму дочернего раздела с `?prefill[client_id]=…`).
     *
     * ```php
     * public function addButtons(Model $record): array
     * {
     *     return [['label' => 'Добавить заявку', 'url' => route('fastleo.resources.create', ['resource' => 'offers', 'prefill' => ['client_id' => $record->getKey()]])]];
     * }
     * ```
     *
     * @return list<array{label: string, url: string}>
     */
    public function addButtons(Model $record): array
    {
        return [];
    }

    /**
     * Текст окна подтверждения удаления записи вместо стандартного: например, что вместе с ней пропадут связанные данные.
     * null — стандартный текст.
     */
    public function deleteWarning(Model $record): ?string
    {
        return null;
    }

    /**
     * Варианты числа записей на странице для выбора под списком: числа из `$perPageOptions` (или `fastleo.list.per_page_options`)
     * в пределах `fastleo.list.max_all`, плюс обычное число записей раздела; по возрастанию. Пусто — выбора нет.
     *
     * @return list<int>
     */
    public static function perPageOptions(): array
    {
        // Опубликованный до появления опции конфиг ключа не содержит (вложенные массивы Laravel не сливает): умолчание пакета.
        $configured = config('fastleo.list.per_page_options', [25, 50, 100]);
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

        return new $model();
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
        return $this->schema ??= new FormSchema($this->fields(), static::model(), inResource: true);
    }

    /**
     * Фильтры списка, привязанные к ресурсу, по имени, для администратора: без фильтров по полям, скрытым от него
     * (`hidden(fn …)`). Иначе по числу найденных записей и вариантам можно было бы подобрать значение скрытого поля.
     * Список для описания ресурса без пользователя (`fastleo:doctor`, `fastleo:inspect`) — `allFilters()`.
     *
     * @return array<string, Filter>
     *
     * @throws LogicException два фильтра с одним именем
     */
    public function listFilters(): array
    {
        return $this->visibleFilters ??= array_filter($this->allFilters(), fn (Filter $filter, string $name): bool => ! $this->schema()->field($name)?->isHidden(), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Все фильтры списка, привязанные к ресурсу, по имени, независимо от того, кому скрыты их поля. Замыкание
     * `hidden(fn …)` здесь не вызывается, поэтому описание ресурса работает и без вошедшего администратора.
     *
     * ```php
     * array_keys($resource->allFilters()); // ['status', 'category_id']
     * ```
     *
     * @return array<string, Filter>
     *
     * @throws LogicException два фильтра с одним именем
     */
    public function allFilters(): array
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
        $placed = [];

        foreach ($this->schema()->tabTables() as ['related' => $related, 'key' => $key, 'topLevel' => $topLevel]) {
            if (! $topLevel) {
                throw new LogicException(__('fastleo::exceptions.related_tab_nested', ['relation' => $related->name], Fastleo::LOCALE));
            }

            if ($related->isAside()) {
                throw new LogicException(__('fastleo::exceptions.related_tab_aside', ['relation' => $related->name], Fastleo::LOCALE));
            }

            $placed[] = $related->placeInTab($key);
        }

        foreach ([...$this->relations(), ...$placed] as $related) {
            if (isset($relations[$related->name])) {
                throw new LogicException(__('fastleo::exceptions.related_duplicate', ['relation' => $related->name, 'resource' => static::class], Fastleo::LOCALE));
            }

            $relations[$related->name] = $related->bind($this);

            // Раскрывать нечего: у дочернего раздела нет своих связанных таблиц.
            if ($related->isExpandable() && $related->child()->relations() === [] && $related->child()->schema()->tabTables() === []) {
                throw new LogicException(__('fastleo::exceptions.related_expandable', ['relation' => $related->name, 'class' => $related->resource], Fastleo::LOCALE));
            }
        }

        return $this->relations = $relations;
    }

    /**
     * Все действия раздела по ключу: встроенные (удалить; в корзине — восстановить, удалить навсегда; с `$readColumn` —
     * «Просмотрено», «Не просмотрено») и `actions()`. Ключи встроенных (`RESERVED_ACTION_KEYS`) своим действиям не даются.
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
        $builtIn = static::usesSoftDeletes() ? [new DeleteRecords(), new RestoreRecords(), new ForceDeleteRecords()] : [new DeleteRecords()];

        if (static::readColumn() !== null) {
            $builtIn = [MarkRead::make(true), MarkRead::make(false), ...$builtIn];
        }

        foreach ($builtIn as $action) {
            $actions[$action->key()] = $action->forResource($this);
        }

        foreach ($this->actions() as $action) {
            $key = $action->key();

            if (isset($actions[$key]) || in_array($key, self::RESERVED_ACTION_KEYS, true) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) !== 1) {
                throw new LogicException(__('fastleo::exceptions.action_key', ['action' => $action::class, 'key' => $key, 'resource' => static::class, 'reserved' => implode(', ', self::RESERVED_ACTION_KEYS)], Fastleo::LOCALE));
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
     * Столбцы таблицы списка: поля списка без картинки, которая стоит в ячейке заголовка (`Image::inTitle()`),
     * и без столбцов с `hideWhenEmpty()`, где на показанной странице (`$records`) все значения пустые.
     * Первый столбец и столбец сортировки `$sorted` остаются всегда.
     *
     * ```php
     * $resource->listColumns($records, 'phone2');
     * ```
     *
     * @param  iterable<Model>  $records  записи страницы; пустая страница ничего не скрывает
     * @return list<Field>
     */
    public function listColumns(iterable $records = [], ?string $sorted = null): array
    {
        $image = $this->titleImage();
        $columns = array_values(array_filter($this->listFields(), fn (Field $field): bool => $field !== $image));
        $records = is_array($records) ? $records : iterator_to_array($records, false);

        if ($records === []) {
            return $columns;
        }

        return array_values(array_filter($columns, fn (Field $field, int $index): bool => $index === 0
            || $field->name === $sorted
            || ! $field->isHiddenWhenEmpty()
            || array_any($records, fn (Model $record): bool => ! $field->isEmptyInList($record)), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Картинка, миниатюра которой стоит перед заголовком в первой ячейке списка (`Image::inTitle()`), или null.
     */
    public function titleImage(): ?Image
    {
        foreach ($this->listFields() as $field) {
            if ($field instanceof Image && $field->isInTitle()) {
                return $field;
            }
        }

        return null;
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
