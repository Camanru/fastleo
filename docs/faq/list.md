# FAQ: Список, поиск, фильтры, действия, экспорт и импорт

Как настроить таблицу записей раздела, поиск, фильтры, кнопки-действия, выгрузку в Excel и загрузку из него. Все вопросы — [список](README.md).

## Столбцы

### Как убрать столбец из списка или показать скрытый?

Вызовите `hideInList()`, чтобы убрать столбец, и `showInList()`, чтобы показать поле, скрытое по умолчанию (`Textarea`, `RichText`, `BelongsToMany` и другие).

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;

Text::make('sku', 'Артикул')->hideInList(),
Textarea::make('excerpt', 'Анонс')->showInList(),      // первые 100 символов
BelongsToMany::make('tags', 'Метки')->showInList(),    // подписи через запятую
```

Первым столбцом ставьте название: он же ссылка на запись и заголовок формы.

Подробнее — [Какие поля видны](../list.md#какие-поля-видны).

### Как показать в списке дату создания, которой нет в форме?

Опишите поле с `onlyInList()` — столбец есть, а в форме поля нет, и оно не записывается.

```php
// app/Fastleo/OrderResource.php
use Fastleo\Fields\DateTime;

DateTime::make('created_at', 'Создан')->onlyInList()->sortable(),
```

Подробнее — [Какие поля видны](../list.md#какие-поля-видны).

### Как изменить вид значения в ячейке (цена с рублём, ссылка)?

Задайте `formatUsing()` для простого случая или `listView()` со своим Blade-шаблоном.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;

Number::make('price', 'Цена')
    ->formatUsing(fn ($value) => $value === null ? null : number_format((float) $value, 0, ',', ' ').' ₽'),

Text::make('sku', 'Артикул')->listView('admin.sku-cell'),
```

```blade
{{-- resources/views/admin/sku-cell.blade.php --}}
<a href="{{ route('products.show', $record) }}" target="_blank">{{ $value }}</a>
```

HTML из `formatUsing()` выводится только через `HtmlString`, обычная строка экранируется. Классы Tailwind в шаблонах сайта не работают — стили пишите в своём CSS ([оформление](../theming.md)).

Подробнее — [Общие методы полей](../fields/README.md#общие-методы).

### Как показать в столбце число заказов или сумму продаж?

Посчитайте значение в `query()` ресурса (`withCount`, `withSum`, подзапрос) и опишите поле с тем же именем и `fromQuery()`.

```php
// app/Fastleo/ClientResource.php
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query->withCount('orders')->withSum('orders as revenue', 'total');
}

public function fields(): array
{
    return [
        Text::make('name', 'Клиент')->searchable()->sortable(),
        Number::make('orders_count', 'Заказов')->fromQuery()->sortable(),
        Number::make('revenue', 'Выручка')->fromQuery()->sortable()
            ->formatUsing(fn ($value) => number_format((float) $value, 0, ',', ' ').' ₽'),
    ];
}
```

Сортировать по такому столбцу можно, искать и фильтровать — нет. Он же попадает в выгрузку Excel.

Подробнее — [Вычисляемые столбцы](../list.md#вычисляемые-столбцы).

### Как показать статусы цветными метками?

Вызовите `badges()` у `Select` с массивом тонов или у `Enum` с методом `tone()` в enum. Тона: `success`, `warning`, `danger`, `info`, `neutral`.

```php
// app/Fastleo/OrderResource.php
use App\Enums\OrderStatus;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Select;

Select::make('payment', 'Оплата')
    ->options(['wait' => 'Ждём', 'paid' => 'Оплачено', 'refund' => 'Возврат'])
    ->badges(['wait' => 'warning', 'paid' => 'success', 'refund' => 'danger']),

Enum::make('status', 'Статус')->enum(OrderStatus::class)->badges(),
```

```php
// app/Enums/OrderStatus.php — рядом с label()
public function tone(): string
{
    return match ($this) {
        self::New => 'info',
        self::Paid => 'success',
        self::Shipped => 'neutral',
    };
}
```

Подробнее — [Цветные метки](../fields/select.md#цветные-метки), [Enum](../fields/enum.md).

### Как подсветить строку списка (просрочено, выполнено)?

Переопределите `rowTone()` ресурса: верните тон или `null`.

```php
// app/Fastleo/TaskResource.php
use Illuminate\Database\Eloquent\Model;

public function rowTone(Model $record): ?string
{
    // done_at и due_at — колонки с кастом datetime
    return match (true) {
        $record->done_at !== null => 'success',
        $record->due_at?->isPast() => 'danger',
        default => null,
    };
}
```

Цвет — подсказка: сам статус покажите и текстом (меткой `badges()`).

Подробнее — [Подсветка строк](../list.md#подсветка-строк).

## Поиск и сортировка

### Как включить поиск по списку?

Отметьте нужные поля `searchable()` — над списком появится строка поиска по подстроке в этих полях.

```php
// app/Fastleo/ClientResource.php
use Fastleo\Fields\Email;
use Fastleo\Fields\Text;

Text::make('name', 'Имя')->searchable()->sortable(),
Email::make('email', 'Email')->searchable(),
```

На больших таблицах отмечайте одно-два коротких поля: поиск по подстроке не ускоряется индексом. Чтобы раздел не участвовал в общем поиске Ctrl+K, задайте `protected static bool $globallySearchable = false;`.

Подробнее — [Поиск](../list.md#поиск), [Глобальный поиск](../list.md#глобальный-поиск).

### Как задать сортировку по умолчанию и сортировку по столбцам?

Задайте `$defaultOrder` (по умолчанию новые сверху) и отметьте столбцы `sortable()` — клик по заголовку сортирует.

```php
// app/Fastleo/DoctorResource.php
use Fastleo\Fields\Text;

protected static ?array $defaultOrder = ['name', 'asc'];

public function fields(): array
{
    return [
        Text::make('name', 'Имя')->sortable(),
    ];
}
```

Колонкам, по которым сортируют и фильтруют, добавьте индекс в миграции: `$table->string('status', 20)->index();`. С ручным порядком (`$sortColumn`) `$defaultOrder` не действует.

Подробнее — [Сортировка](../list.md#сортировка).

### Как изменить число записей на странице?

Задайте `$perPage` и варианты выбора «На странице» `$perPageOptions` (`[]` — без выбора). Для всего сайта — `list.per_page` в `config/fastleo.php`.

```php
// app/Fastleo/OrderResource.php
protected static ?int $perPage = 50;

protected static ?array $perPageOptions = [50, 100, 200];
```

Подробнее — [Страницы](../list.md#страницы).

## Фильтры

### Как добавить фильтры над списком?

Опишите готовые фильтры в `filters()`. Варианты и подписи `SelectFilter` и `BelongsToFilter` берут у одноимённого поля ресурса.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\BelongsToManyFilter;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\SelectFilter;

public function filters(): array
{
    return [
        SelectFilter::make('status'),                  // поле Select/Enum status
        BelongsToFilter::make('category_id'),          // поле BelongsTo category_id
        BelongsToManyFilter::make('tags', 'Метка'),    // поле BelongsToMany tags
        BooleanFilter::make('is_active', 'Показывается'),
        DateRangeFilter::make('created_at', 'Создан'), // колонка без поля в форме
    ];
}
```

Подробнее — [Фильтры](../list.md#фильтры).

### Как сделать фильтр по своему условию?

Задайте варианты `options()` и условие `using()` — замыкание получает запрос и выбранное значение.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

SelectFilter::make('stock_state', 'Остаток')
    ->options(['out' => 'Нет в наличии', 'low' => 'Меньше 5'])
    ->using(fn (Builder $query, string $value) => $value === 'out'
        ? $query->where('stock', 0)
        : $query->whereBetween('stock', [1, 4])),

BooleanFilter::make('has_photo', 'С фото')
    ->using(fn (Builder $query, bool $value) => $value ? $query->whereNotNull('photo') : $query->whereNull('photo')),
```

Подробнее — [Фильтры](../list.md#фильтры).

### Как сделать фильтр со своим полем ввода («Цена от»)?

Создайте заготовку командой и поправьте проверку значения, условие и шаблон.

```bash
php artisan fastleo:make-filter PriceFrom
```

```php
// app/Fastleo/Filters/PriceFrom.php
namespace App\Fastleo\Filters;

use Fastleo\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

final class PriceFrom extends Filter
{
    /** Число из адреса или null — фильтр выключен. */
    #[Override]
    public function value(mixed $input): ?int
    {
        return is_string($input) && ctype_digit($input) ? (int) $input : null;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo.filters.price-from';
    }

    /**
     * @param  Builder<Model>  $query
     */
    #[Override]
    protected function query(Builder $query, mixed $value): void
    {
        $query->where($query->qualifyColumn($this->name), '>=', $value);
    }
}
```

```blade
{{-- resources/views/fastleo/filters/price-from.blade.php --}}
<label class="flex min-w-0 flex-col gap-1.5 text-sm">
    <span class="font-medium text-ink-2">{{ $filter->getLabel() }}</span>
    <input type="number" min="0" name="filter[{{ $filter->name }}]" form="{{ $form }}" value="{{ $value }}" class="fastleo-input" x-on:change="$el.form.requestSubmit()">
</label>
```

Классы в шаблоне взяты из заготовки `fastleo:make-filter` и уже есть в стилях пакета. Свои классы Tailwind в шаблонах сайта не сработают: стили админки собраны заранее. Для своего оформления пишите обычный CSS в `fastleo.css` — [свой CSS](../theming.md#свой-css).

```php
// app/Fastleo/ProductResource.php
use App\Fastleo\Filters\PriceFrom;

public function filters(): array
{
    return [PriceFrom::make('price', 'Цена от')];
}
```

Подробнее — [Фильтры](../list.md#фильтры).

### Как открыть список уже отфильтрованным («только новые», «менеджер — я»)?

Задайте фильтру `default()`. Администратор может выбрать «Все», а кнопка «Сбросить» вернёт умолчание.

```php
// app/Fastleo/OrderResource.php
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\SelectFilter;

SelectFilter::make('status')->default('new'),
BelongsToFilter::make('manager_id', 'Менеджер')->default(fn () => auth('fastleo')->id()),
DateRangeFilter::make('created_at', 'Создан')->default(fn () => [
    'from' => today()->subDays(7)->toDateString(),
    'to' => today()->toDateString(),
]),
```

Это удобство, а не защита: чтобы менеджер не видел чужие записи, ограничьте `query()` — [FAQ: права](access.md).

Подробнее — [Фильтры](../list.md#фильтры).

## Порядок и обновление

### Как править цену или статус прямо в списке?

Вызовите `inlineEditable()` у поля: клик по ячейке, правка, Enter — сохранено. Подходят текст, число, `Select`, `Enum`, `Radio` и `BelongsTo`.

```php
// app/Fastleo/ProductResource.php
use App\Enums\ProductStatus;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Number;

Number::make('price', 'Цена')->min(0)->inlineEditable(),
Enum::make('status', 'Статус')->enum(ProductStatus::class)->inlineEditable(),
BelongsTo::make('manager_id', 'Менеджер')->inlineEditable(),
```

Поле не должно быть `readonly()` и участвовать в `visibleWhen()`/`dependsOn()`. Нужно право `update` на запись.

Подробнее — [Правка в ячейке](../list.md#правка-в-ячейке).

### Как включать и выключать галочку одним кликом в списке?

Вызовите `quickToggle()` у `Toggle` или `Checkbox` — значок в столбце станет кнопкой.

```php
// app/Fastleo/ServiceResource.php
use Fastleo\Fields\Toggle;

Toggle::make('is_active', 'Показывать на сайте')->default(true)->quickToggle(),
```

Поле не должно быть `readonly()`, `onlyInList()` или `hideOnEdit()`.

Подробнее — [Быстрый переключатель в списке](../fields/toggle.md#быстрый-переключатель-в-списке).

### Как сделать, чтобы новые заявки появлялись в списке без перезагрузки?

Задайте `$poll` — период обновления в секундах (от 5 до 3600).

```php
// app/Fastleo/LeadResource.php
protected static ?int $poll = 15;
```

Пока администратор выбирает записи или печатает в ячейке, список не обновляется — набранное не пропадёт.

Подробнее — [Автообновление списка](../list.md#автообновление-списка).

### Как расставлять записи вручную перетаскиванием?

Добавьте целую колонку порядка и укажите её в `$sortColumn` — появятся стрелки и ручка. В `$fillable` и `fields()` колонку добавлять не нужно.

```php
// миграция
$table->unsignedInteger('sort')->default(0);
```

```php
// app/Fastleo/SlideResource.php
protected static ?string $sortColumn = 'sort';
```

На сайте выводите в том же порядке: `Slide::query()->orderBy('sort')->get()`. Стрелки скрыты, пока список отсортирован по столбцу, отфильтрован или идёт поиск.

Подробнее — [Ручной порядок](../resources.md#ручной-порядок).

### Как показать категории деревом?

Добавьте колонку родителя, связь `parent()` в модели, `$treeColumn` в ресурсе и поле `BelongsTo` для выбора родителя.

```php
// миграция
$table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
$table->unsignedInteger('sort')->default(0);
```

```php
// app/Models/Category.php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function parent(): BelongsTo
{
    return $this->belongsTo(self::class, 'parent_id');
}
```

```php
// app/Fastleo/CategoryResource.php
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;

protected static ?string $treeColumn = 'parent_id';

protected static ?string $sortColumn = 'sort';   // порядок внутри ветки, необязательно

public function fields(): array
{
    return [
        Text::make('title', 'Название')->required(),
        BelongsTo::make('parent_id', 'Родитель')->hideInList(),
    ];
}
```

`parent_id` — в `$fillable` модели. Запись с вложенными удалить нельзя, пока их не перенесут.

Подробнее — [Дерево](../resources.md#дерево).

## Действия

### Как добавить кнопку-действие над записью и выбранными записями?

Создайте класс действия командой, опишите `handle()` и подключите его в `actions()` ресурса.

```bash
php artisan fastleo:make-action PublishProducts
```

```php
// app/Fastleo/Actions/PublishProducts.php
namespace App\Fastleo\Actions;

use App\Models\Product;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;

final class PublishProducts extends Action
{
    protected ?string $label = 'Опубликовать';

    protected string $icon = 'send';

    protected ?string $confirm = 'Опубликовать выбранные товары?';

    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Product $product) => $product->update(['is_active' => true]));

        return ActionResult::success("Опубликовано: {$records->count()}");
    }
}
```

```php
// app/Fastleo/ProductResource.php
use App\Fastleo\Actions\PublishProducts;

public function actions(): array
{
    return [new PublishProducts];
}
```

В `handle()` приходят только записи, на которые у администратора есть право (`update` по умолчанию, другое — `$ability`).

Подробнее — [Своё действие](../actions.md#своё-действие).

### Как спросить данные перед запуском действия?

Опишите поля в `fields()` действия — откроется окно с мини-формой, значения придут в `$data` уже проверенными.

```bash
php artisan fastleo:make-action ShipOrders        # app/Fastleo/Actions/ShipOrders.php
```

```php
// app/Fastleo/Actions/ShipOrders.php
namespace App\Fastleo\Actions;

use App\Models\Order;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\Date;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Collection;

final class ShipOrders extends Action
{
    protected ?string $label = 'Отправить';

    protected string $icon = 'truck';

    public function fields(): array
    {
        return [
            Date::make('shipped_on', 'Дата отправки')->required()->default(fn () => today()),
            Text::make('track', 'Трек-номер')->maxLength(50),
        ];
    }

    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Order $order) => $order->update([
            'status' => 'shipped',
            'shipped_on' => $data['shipped_on'],
            'track' => $data['track'] ?? null,
        ]));

        return ActionResult::success('Заказы отправлены.');
    }
}
```

В мини-форме работают простые поля: текст, число, дата и время, список, галочка, картинка и файл.

Подробнее — [Мини-форма](../actions.md#мини-форма).

### Как сделать действие только для одной записи, красным или с другим правом?

Задайте свойства класса действия.

```bash
php artisan fastleo:make-action CancelOrder       # app/Fastleo/Actions/CancelOrder.php
```

```php
// app/Fastleo/Actions/CancelOrder.php
protected ?string $label = 'Отменить заказ';

protected string $icon = 'x-circle';

protected ?string $confirm = 'Отменить заказ? Клиент получит письмо.';

protected bool $danger = true;      // красная кнопка

protected bool $bulk = false;       // только у одной записи

protected string $ability = 'cancel';   // метод cancel() в OrderPolicy
```

Если метода из `$ability` нет в Policy, действие запрещено; без Policy у модели — разрешено всем. Для действий, которые ничего не меняют (выгрузка, письмо), ставьте `$ability = 'view'`.

Подробнее — [Свойства действия](../actions.md#свойства), [Права](../actions.md#права).

### Как спрятать кнопку действия у записей, которым она не нужна?

Переопределите `visibleFor()` в классе действия.

```php
// app/Fastleo/Actions/PublishProducts.php
use Illuminate\Database\Eloquent\Model;

public function visibleFor(Model $record): bool
{
    return ! $record->is_active;
}
```

Это только про кнопку у одной записи: в полосе выбранных записей кнопка видна всегда, а запуск всё равно проверяет право.

Подробнее — [Права](../actions.md#права).

### Как после действия перейти на другую страницу или отдать файл?

Верните `ActionResult::redirect()` или `ActionResult::download()`.

```bash
php artisan fastleo:make-action ExportInvoices    # app/Fastleo/Actions/ExportInvoices.php
```

```php
// app/Fastleo/Actions/ExportInvoices.php
namespace App\Fastleo\Actions;

use App\Models\Order;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;

final class ExportInvoices extends Action
{
    protected ?string $label = 'Счета в CSV';

    protected string $icon = 'download';

    protected string $ability = 'view';

    public function handle(Collection $records, array $data): ActionResult
    {
        return ActionResult::download(response()->streamDownload(function () use ($records): void {
            $out = fopen('php://output', 'w');

            foreach ($records as $order) {
                /** @var Order $order */
                fputcsv($out, [$order->id, $order->total], ';', escape: '');
            }

            fclose($out);
        }, 'invoices.csv'));
    }
}

// переход: return ActionResult::redirect(route('fastleo.resources.show', ['clients', $clientId], false), 'Клиент открыт');
```

Долгую работу (рассылку) отправьте из `handle()` в очередь: `SendNewsletter::dispatch($records->modelKeys())` — [FAQ: расчёты и очереди](algorithms.md).

Подробнее — [Результат](../actions.md#результат).

### Как показать или скрыть сразу много записей?

Подключите готовое действие `SetToggle` для галочки раздела — свой класс не нужен.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Actions\SetToggle;

public function actions(): array
{
    return [
        SetToggle::make('is_active', 'Показать на сайте', true),
        SetToggle::make('is_active', 'Скрыть с сайта', false),
    ];
}
```

Первый аргумент — имя поля `Toggle` или `Checkbox` из `fields()`. Нужно право `update`.

Подробнее — [Готовое действие «Показать» / «Скрыть»](../actions.md#готовое-действие-показать--скрыть).

## Экспорт и импорт

### Как выгрузить список в Excel и как отключить выгрузку?

Кнопка «Экспорт» (xlsx или csv) есть в каждом разделе — выгружается то, что сейчас показывает список. Отключить её для раздела или убрать из файла отдельное поле:

```php
// app/Fastleo/ClientResource.php
use Fastleo\Fields\Textarea;

protected static bool $exportable = false;   // кнопки нет

public function fields(): array
{
    return [
        Textarea::make('admin_note', 'Заметка для админов')->exportable(false),   // не в файле
    ];
}
```

Разрешить выгрузку не всем — метод `export()` в Policy модели ([FAQ: права](access.md)). Для таблиц в сотни тысяч строк выбирайте csv.

Подробнее — [Экспорт](../import-export.md#экспорт).

### Как добавить в выгрузку данные из связанных таблиц?

Посчитайте их подзапросом в `query()` ресурса и опишите полями с `fromQuery()` — они попадут и в список, и в файл.

```php
// app/Fastleo/OrderResource.php
use App\Models\Client;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query->addSelect([
        'client_name' => Client::select('name')->whereColumn('clients.id', 'orders.client_id')->limit(1),
        'client_phone' => Client::select('phone')->whereColumn('clients.id', 'orders.client_id')->limit(1),
    ]);
}

// в fields()
Text::make('client_name', 'Клиент')->fromQuery(),
Tel::make('client_phone', 'Телефон')->fromQuery(),
```

Подробнее — [Выгрузка с данными из связанных таблиц](../recipes.md#выгрузка-с-данными-из-связанных-таблиц).

### Как загрузить товары из Excel и обновлять их по артикулу?

Включите `$importable` и задайте `$importKey` — строки с найденным артикулом обновятся, с новым — создадутся.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;

protected static bool $importable = true;

protected static ?string $importKey = 'sku';

public function fields(): array
{
    return [
        Text::make('title', 'Название')->required(),
        Text::make('sku', 'Артикул')->required(),
        Number::make('price', 'Цена')->min(0),
    ];
}
```

```php
// миграция
$table->string('sku')->unique();
```

1. Выгрузите раздел кнопкой «Экспорт» — получится файл с правильными заголовками.
2. Поправьте или добавьте строки и загрузите файл кнопкой «Импорт».
3. Для файлов больше нескольких тысяч строк включите очередь (`QUEUE_CONNECTION=database`, `php artisan queue:work`) и поставьте `retry_after` больше 3600 в `config/queue.php`.

Строки с ошибками попадут в отчёт и в файл «пропущенные строки» — исправьте его и загрузите снова.

Подробнее — [Импорт](../import-export.md#импорт), [Новая запись или обновление](../import-export.md#новая-запись-или-обновление).

## Связанные таблицы

### Как показать связанные записи на странице записи?

Опишите `relations()` в ресурсе родителя: первый аргумент — метод `HasMany` модели, второй — ресурс дочернего раздела. В дочернем разделе нужно поле `BelongsTo` на родителя.

```php
// app/Fastleo/ClientResource.php
use App\Fastleo\OrderResource;
use App\Fastleo\TaskResource;
use Fastleo\Related;

public function relations(): array
{
    return [
        Related::make('orders', OrderResource::class)->label('Заказы клиента')->perPage(10),
        Related::make('tasks', TaskResource::class)->aside(),   // справа от формы
    ];
}
```

```php
// app/Fastleo/OrderResource.php — в fields()
BelongsTo::make('client_id', 'Клиент')->required(),
```

```php
// app/Models/Client.php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function orders(): HasMany
{
    return $this->hasMany(Order::class);
}
```

Столбцы, действия и права берутся из дочернего раздела. Кнопка «Добавить» открывает форму с уже выбранным клиентом. Не выводите ту же связь ещё и полем `HasMany` в форме правки.

Подробнее — [Связанные таблицы](../related.md), [Боковая колонка карточки](../related.md#боковая-колонка-карточки).

### Как добавлять дочерние записи прямо под таблицей, без перехода на форму?

Вызовите `quickAdd()` с именами полей дочернего раздела — под таблицей появится маленькая форма.

```php
// app/Fastleo/ClientResource.php
use Fastleo\Related;

Related::make('tasks', TaskResource::class)
    ->quickAdd(['title', 'due_at'])
    ->addLabel('Добавить задачу'),
```

Обязательные поля дочернего раздела должны входить в `quickAdd()` или иметь `default()`, иначе `fastleo:doctor` предупредит. Задачи и комментарии в CRM — [FAQ: CRM](crm.md).

Подробнее — [Быстрое добавление](../related.md#быстрое-добавление).
