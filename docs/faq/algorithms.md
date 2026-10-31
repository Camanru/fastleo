# FAQ: Логика и сложные сценарии

Расчёты при сохранении, статусы, действия с транзакцией, очередь и расписание, дерево и порядок, импорт по артикулу, кеш, удаление со связями. Все вопросы — [список](README.md).

В примерах модели `Order`, `OrderItem`, `Product`, `Category`; администратор — `App\Models\User`. Подставьте свои имена.

## Расчёты при сохранении

### Как посчитать сумму заказа из позиций?

Храните сумму колонкой `total` и пересчитывайте её в observer позиции — при сохранении и удалении каждой позиции. В ресурсе сделайте сумму полем только для чтения.

```php
// app/Models/Order.php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['client_name', 'phone'])] // total не в $fillable: его пишет только код
class Order extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function recalculateTotal(): void
    {
        $this->forceFill(['total' => $this->items()->sum(DB::raw('quantity * price'))])->saveQuietly();
    }
}
```

```php
// app/Models/OrderItem.php
namespace App\Models;

use App\Observers\OrderItemObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(OrderItemObserver::class)]
#[Fillable(['product_id', 'quantity', 'price'])] // order_id пишет пакет
class OrderItem extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

```php
// app/Observers/OrderItemObserver.php
namespace App\Observers;

use App\Models\OrderItem;

final class OrderItemObserver
{
    public function saved(OrderItem $item): void
    {
        $item->order->recalculateTotal();
    }

    public function deleted(OrderItem $item): void
    {
        $item->order->recalculateTotal();
    }
}
```

```php
// app/Fastleo/OrderResource.php — фрагмент fields()
HasMany::make('items', 'Позиции')->fields([
    Grid::make(3, [
        BelongsTo::make('product_id', 'Товар')->required(),
        Number::make('quantity', 'Кол-во')->integer()->min(1)->required(),
        Number::make('price', 'Цена')->min(0)->step(0.01)->required(),
    ]),
])->min(1),
Number::make('total', 'Сумма')->readonly()->sortable(),
```

Миграция: `$table->decimal('total', 12, 2)->default(0);`. Сумма пересчитается при любом изменении позиций: из формы, импорта и кода сайта.

Подробнее — [HasMany](../fields/has-many.md), [события модели](../resources.md#события-модели).

### Как посчитать цену со скидкой при сохранении?

Считайте итоговую цену в `saving` observer товара и храните её колонкой — тогда по ней можно сортировать и фильтровать список.

```php
// app/Observers/ProductObserver.php
namespace App\Observers;

use App\Models\Product;

final class ProductObserver
{
    public function saving(Product $product): void
    {
        $product->final_price = round((float) $product->price * (100 - (int) $product->discount) / 100, 2);
    }
}
```

```php
// app/Models/Product.php
#[ObservedBy(ProductObserver::class)]
#[Fillable(['name', 'sku', 'price', 'discount', 'stock'])] // final_price — не в $fillable
class Product extends Model {}
```

```php
// app/Fastleo/ProductResource.php — фрагмент fields()
Number::make('price', 'Цена')->min(0)->step(0.01)->required(),
Number::make('discount', 'Скидка, %')->integer()->min(0)->max(90)->emptyValue(0),
Number::make('final_price', 'Цена со скидкой')->readonly()->sortable(),
```

Цена пересчитается и при правке в ячейке списка, и при импорте. Подробнее — [запись в модель](../resources.md#запись-в-модель).

### Как пересчитать уже сохранённые записи после смены формулы?

Пересохраните записи через модель одной командой: `saving` срабатывает и у записи без изменений, observer посчитает новое значение, и оно запишется.

```bash
php artisan tinker --execute="App\Models\Product::query()->lazyById(500)->each->save()"
```

Для больших таблиц оформите то же artisan-командой и запустите на сервере. Журнал изменений админки такие правки не записывает. Подробнее — [события модели](../resources.md#события-модели).

### Как давать заказам номер по порядку без дублей и пропусков?

Ведите счётчик в отдельной таблице и увеличивайте его в `creating`. Админка сохраняет запись в транзакции: строка счётчика заблокирована до конца сохранения, а отменённое сохранение откатывает и счётчик.

```php
// database/migrations/2026_01_01_000010_create_order_counters_table.php
Schema::create('order_counters', function (Blueprint $table) {
    $table->unsignedSmallInteger('year')->primary();
    $table->unsignedInteger('last')->default(0);
});

Schema::table('orders', function (Blueprint $table) {
    $table->string('number', 20)->nullable()->unique();
});
```

```php
// app/Observers/OrderObserver.php
namespace App\Observers;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class OrderObserver
{
    public function creating(Order $order): void
    {
        $year = now()->year;

        DB::table('order_counters')->insertOrIgnore(['year' => $year, 'last' => 0]);
        DB::table('order_counters')->where('year', $year)->increment('last'); // блокирует строку до конца транзакции
        $last = DB::table('order_counters')->where('year', $year)->value('last');

        $order->number = sprintf('%d-%05d', $year, $last); // 2026-00042
    }
}
```

```php
// app/Fastleo/OrderResource.php — фрагмент fields()
Text::make('number', 'Номер')->readonly()->searchable()->sortable(),
```

Подключите observer атрибутом `#[ObservedBy(OrderObserver::class)]` у модели `Order`. В коде сайта тоже создавайте заказ в транзакции: `DB::transaction(fn () => Order::create($data))` — иначе блокировка снимется сразу. Уникальный индекс на `number` — последняя защита от дубля.

## Статусы и действия

### Как разрешить только допустимые переходы статуса?

Опишите переходы в enum и добавьте полю правило: допустимы текущий статус и переходы из него. Прыжок «Новый → Выполнен» получит ошибку под полем — в форме, при правке в ячейке и при импорте.

```php
// app/Enums/OrderStatus.php
namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::Paid => 'Оплачен',
            self::Shipped => 'Отгружен',
            self::Done => 'Выполнен',
            self::Cancelled => 'Отменён',
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::New => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Done],
            self::Done, self::Cancelled => [],
        };
    }

    /** @return list<string> текущий статус и разрешённые переходы */
    public function allowedValues(): array
    {
        return array_map(fn (self $status): string => $status->value, [$this, ...$this->next()]);
    }
}
```

```php
// app/Fastleo/OrderResource.php — фрагмент fields()
use App\Enums\OrderStatus;
use Fastleo\Fields\Enum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

Enum::make('status', 'Статус')->enum(OrderStatus::class)->required()->default(OrderStatus::New->value)
    ->rules(fn (?Model $record) => [Rule::in($record?->status?->allowedValues() ?? [OrderStatus::New->value])]),
```

В модели нужен каст `'status' => OrderStatus::class`. Ошибка — обычный текст правила `in`; свой текст задайте в `lang/ru/validation.php`.

Подробнее — [свои правила](../validation.md#свои-правила), [тексты ошибок](../validation.md#тексты-ошибок).

### Как менять статус только кнопками «Оплачен», «Отгружен»?

Сделайте поле статуса `readonly()` и опишите по действию на каждый переход. `visibleFor()` прячет кнопку у записи, где переход невозможен; проверка в `handle()` пропускает такие записи среди выбранных.

```php
// app/Fastleo/Actions/MarkPaid.php
namespace App\Fastleo\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class MarkPaid extends Action
{
    protected ?string $label = 'Оплачен';
    protected string $icon = 'wallet';
    protected ?string $confirm = 'Отметить заказ оплаченным?';

    public function visibleFor(Model $record): bool
    {
        return $record->status === OrderStatus::New;
    }

    public function handle(Collection $records, array $data): ActionResult
    {
        $paid = $records
            ->filter(fn (Order $order): bool => $order->status === OrderStatus::New)
            ->filter(fn (Order $order): bool => $order->update(['status' => OrderStatus::Paid])); // false — observer отменил

        return ActionResult::success("Отмечено оплаченными: {$paid->count()} из {$records->count()}");
    }
}
```

```php
// app/Fastleo/OrderResource.php
Enum::make('status', 'Статус')->enum(OrderStatus::class)->readonly()->badges(),

public function actions(): array
{
    return [new MarkPaid, new ShipOrders];
}
```

`readonly()` запрещает менять статус формой, а `update()` в действии пишет через модель — поэтому `status` всё равно должен быть в `$fillable`. Подробнее — [своё действие](../actions.md#своё-действие), [права действия](../actions.md#права).

### Как сделать действие с проверкой и транзакцией (списать остатки при отгрузке)?

Обрабатывайте каждый заказ в своей `DB::transaction()` и перечитывайте его с `lockForUpdate()`: два администратора не отгрузят один заказ дважды. Причину отказа бросайте своим исключением — транзакция откатится, а текст попадёт в сообщение.

```php
// app/Fastleo/Actions/ShipOrders.php
namespace App\Fastleo\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use DomainException;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ShipOrders extends Action
{
    protected ?string $label = 'Отгрузить';
    protected string $icon = 'truck';
    protected ?string $confirm = 'Отгрузить и списать остатки?';

    public function visibleFor(Model $record): bool
    {
        return $record->status === OrderStatus::Paid;
    }

    public function handle(Collection $records, array $data): ActionResult
    {
        $shipped = 0;
        $errors = [];

        foreach ($records as $order) {
            try {
                DB::transaction(function () use ($order): void {
                    $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());

                    if ($order->status !== OrderStatus::Paid) {
                        throw new DomainException("№{$order->number}: заказ уже не «Оплачен»");
                    }

                    foreach ($order->items as $item) {
                        $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);

                        if ($product->stock < $item->quantity) {
                            throw new DomainException("№{$order->number}: не хватает «{$product->name}»");
                        }

                        $product->update(['stock' => $product->stock - $item->quantity]);
                    }

                    if (! $order->update(['status' => OrderStatus::Shipped])) {
                        throw new DomainException("№{$order->number}: сайт отменил сохранение"); // откатит и списание
                    }
                });

                $shipped++;
            } catch (DomainException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors === []
            ? ActionResult::success("Отгружено: {$shipped}")
            : ActionResult::error("Отгружено: {$shipped}. Не выполнено: ".implode('; ', $errors));
    }
}
```

Ошибку базы внутри действия админка покажет понятным текстом сама — ловить `QueryException` не нужно. Подробнее — [результат действия](../actions.md#результат).

### Как запретить менять запись из observer (например, выполненный заказ)?

Верните `false` из `updating` (или `saving`, `deleting`): админка откатит всю транзакцию и покажет «Сайт отменил сохранение». Это работает и для кода сайта, и для импорта.

```php
// app/Observers/OrderObserver.php
use App\Enums\OrderStatus;
use App\Models\Order;

public function updating(Order $order): bool
{
    return $order->getOriginal('status') !== OrderStatus::Done;
}
```

Отмена работает только при изменении самой записи. Если в форме поменяли лишь вложенные записи (`HasMany`), `updating` не вызывается и они сохранятся. Действия, которые вызывают `update()`, получают `false` молча — проверяйте результат, как в `MarkPaid` и `ShipOrders` выше. Для полного запрета закройте изменение в Policy — пропадут кнопка «Изменить», правка в списке и действия над такой записью:

```php
// app/Policies/OrderPolicy.php
public function update(User $user, Order $order): bool
{
    return $order->status !== OrderStatus::Done;
}
```

Текст сообщения при отмене общий. Чтобы показать свою причину под полем, проверяйте значение правилом поля (`rules()`).

Подробнее — [запись в модель](../resources.md#запись-в-модель), [события модели](../resources.md#события-модели), [права](access.md).

## Форма

### Как сделать поле обязательным только при условии?

Добавьте полю `required()` и `visibleWhen()`: пока поле скрыто, оно не проверяется и не сохраняется.

```php
// app/Fastleo/OrderResource.php — фрагмент fields()
Select::make('delivery', 'Доставка')->options(['pickup' => 'Самовывоз', 'courier' => 'Курьер'])->required(),
Textarea::make('address', 'Адрес доставки')->required()->visibleWhen('delivery', 'courier'),
DateTime::make('deliver_at', 'Привезти к')->visibleWhen('delivery', 'courier'),
```

Подробнее — [зависимые поля](../layout.md#зависимые-поля).

### Как сделать три связанных списка: регион → город → район?

Задайте каждому следующему списку `dependsOn()` от предыдущего. Сервер проверяет всю цепочку: город другого региона или район другого города не сохранится.

```php
// app/Fastleo/AddressResource.php — фрагмент fields()
use Fastleo\Fields\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

BelongsTo::make('region_id', 'Регион')->required(),
BelongsTo::make('city_id', 'Город')->required()
    ->dependsOn('region_id', fn (Builder $query, mixed $region) => $query->where('region_id', $region ?? 0)),
BelongsTo::make('district_id', 'Район')
    ->dependsOn('city_id', fn (Builder $query, mixed $city) => $query->where('city_id', $city ?? 0)),
```

В модели нужны связи `region()`, `city()`, `district()`. После смены региона выберите город заново — список районов обновится при выборе города. Подробнее — [зависимые списки](../layout.md#зависимые-списки).

## Время и очередь

### Как выполнить действие в момент публикации (уведомить, сбросить кеш)?

Само скрытие до даты делает условие `published_at <= now()` на сайте ([FAQ по страницам](pages.md#как-опубликовать-новость-в-назначенный-день-и-час)). Если в момент публикации нужно что-то сделать, запускайте команду по расписанию и отмечайте обработанные записи.

```php
// app/Console/Commands/AnnouncePublishedNews.php
namespace App\Console\Commands;

use App\Models\News;
use Fastleo\Fastleo;
use Illuminate\Console\Command;

final class AnnouncePublishedNews extends Command
{
    protected $signature = 'news:announce';

    protected $description = 'Действия для новостей, у которых наступило время публикации';

    public function handle(): int
    {
        News::query()
            ->published()                 // включена и дата пустая или наступила
            ->whereNull('announced_at')
            ->lazyById(100)
            ->each(function (News $news): void {
                Fastleo::notifyAdmins("Опубликована новость: {$news->title}");
                $news->forceFill(['announced_at' => now()])->save();
            });

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('news:announce')->everyMinute()->withoutOverlapping();
```

Scope `published()` — из [FAQ по страницам](pages.md#как-опубликовать-новость-в-назначенный-день-и-час). Миграция: `$table->timestamp('announced_at')->nullable();`; у уже вышедших новостей заполните её сразу (`News::query()->published()->update(['announced_at' => now()])`), иначе команда объявит их все при первом запуске. На сервере нужен cron `* * * * * php artisan schedule:run`. Подробнее — [уведомления](../notifications.md#отправить-уведомление), [очередь на хостинге с одним cron](../installation.md#очередь-на-хостинге-с-одним-cron).

### Как запустить тяжёлую обработку после сохранения в очереди?

Отправляйте задачу из observer, который срабатывает после коммита транзакции (`ShouldHandleEventsAfterCommit`): задача не увидит запись, которой ещё нет в базе. Запускайте её, только если изменилось нужное поле.

```php
// app/Observers/ProductObserver.php
namespace App\Observers;

use App\Jobs\GenerateProductPdf;
use App\Models\Product;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class ProductObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Product $product): void
    {
        if ($product->wasRecentlyCreated || $product->wasChanged(['description', 'photo'])) {
            GenerateProductPdf::dispatch($product->id);
        }
    }
}
```

```php
// app/Jobs/GenerateProductPdf.php
namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateProductPdf implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);

        if ($product === null) {
            return;
        }

        // … тяжёлая работа: PDF, внешний API, пересчёт
    }
}
```

На сайте должна работать очередь (`QUEUE_CONNECTION=database` и `php artisan queue:work`); с `sync` задача выполнится сразу, во время сохранения. Подробнее — [события модели](../resources.md#события-модели), [очередь на хостинге с одним cron](../installation.md#очередь-на-хостинге-с-одним-cron).

### Как изменить тысячи записей одним действием?

Сделайте действие с мини-формой, которое только ставит задачу в очередь, а задача обрабатывает записи порциями и сообщает администратору в колокольчик. Действие получает выбранные записи — с «Все» в списке это до 1000 записей за раз.

```php
// app/Fastleo/Actions/RepriceProducts.php
namespace App\Fastleo\Actions;

use App\Jobs\RepriceProductsJob;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fastleo;
use Fastleo\Fields\Number;
use Illuminate\Database\Eloquent\Collection;

final class RepriceProducts extends Action
{
    protected ?string $label = 'Изменить цены на %';
    protected string $icon = 'percent';
    protected bool $single = false;
    protected bool $mcp = false;

    public function fields(): array
    {
        return [Number::make('percent', 'Процент (минус — снизить)')->required()->min(-90)->max(300)];
    }

    public function handle(Collection $records, array $data): ActionResult
    {
        RepriceProductsJob::dispatch($records->modelKeys(), (float) $data['percent'], Fastleo::guard()->id());

        return ActionResult::success("Запущено для {$records->count()} товаров. Когда закончим — сообщим в колокольчике.");
    }
}
```

```php
// app/Jobs/RepriceProductsJob.php
namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RepriceProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /** @param list<int> $ids */
    public function __construct(public array $ids, public float $percent, public int|string|null $adminId) {}

    public function handle(): void
    {
        Product::query()->whereKey($this->ids)->lazyById(200)->each(function (Product $product): void {
            $product->update(['price' => round((float) $product->price * (1 + $this->percent / 100), 2)]);
        });

        $admin = User::find($this->adminId);

        if ($admin !== null) {
            Fastleo::notify($admin, FastleoMessage::make('Цены пересчитаны')->body(count($this->ids).' товаров')->level('success'));
        }
    }
}
```

Записи пишутся через модель — observers срабатывают, но в журнал изменений задача очереди не попадает. Для всей таблицы (десятки тысяч строк) запускайте ту же задачу из artisan-команды. Подробнее — [мини-форма](../actions.md#мини-форма), [ограничения действий](../actions.md#ограничения), [журнал изменений](../activity.md#что-попадает-в-журнал).

## Список

### Как показать в списке значение, посчитанное подзапросом?

Посчитайте его в `query()` ресурса (`withCount`, `withSum` или подзапрос с псевдонимом) и опишите поле с тем же именем и `fromQuery()`.

```php
// app/Fastleo/ProductResource.php
use App\Models\OrderItem;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Number;
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query
        ->withSum('orderItems as sold', 'quantity')
        ->addSelect(['last_sold_at' => OrderItem::select('created_at')
            ->whereColumn('order_items.product_id', 'products.id')
            ->latest()
            ->limit(1)]);
}

public function fields(): array
{
    return [
        Text::make('name', 'Товар')->searchable()->sortable(),
        Number::make('sold', 'Продано, шт.')->fromQuery()->sortable(),
        DateTime::make('last_sold_at', 'Последняя продажа')->fromQuery()->sortable(),
    ];
}
```

Такие столбцы есть в списке, на просмотре и в выгрузке; искать и фильтровать по ним нельзя. `fastleo:doctor` предупредит, если псевдонима нет в `query()`. Подробнее — [вычисляемые столбцы](../list.md#вычисляемые-столбцы).

### Как отсортировать список по вычисляемому значению?

По клику — добавьте `sortable()` полю с `fromQuery()` (пример выше); ссылка на такой порядок — `/fastleo/products?sort=sold&direction=desc`. Порядок по умолчанию (`$defaultOrder`) берёт только колонки таблицы, поэтому значение, по которому список должен быть отсортирован сразу, храните колонкой и пересчитывайте в observer.

```php
// app/Fastleo/OrderResource.php
protected static ?array $defaultOrder = ['total', 'desc']; // total считает OrderItemObserver
```

Как пересчитывать колонку — в вопросе [о сумме заказа](#как-посчитать-сумму-заказа-из-позиций). Подробнее — [сортировка](../list.md#сортировка).

## Дерево и порядок

### Как ограничить глубину дерева (не больше трёх уровней)?

Добавьте полю «Родитель» правило-замыкание: глубина родителя плюс высота переносимой ветки не должны превышать предел. Правило срабатывает и для кнопки «+» у записи в дереве.

```php
// app/Models/Category.php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'parent_id'])]
class Category extends Model
{
    public const int MAX_DEPTH = 3;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Уровень записи: 1 — корень. */
    public function depth(): int
    {
        $depth = 1;

        for ($parent = $this->parent; $parent !== null && $depth <= self::MAX_DEPTH; $parent = $parent->parent) {
            $depth++;
        }

        return $depth;
    }

    /** Сколько уровней под записью: 0 — вложенных нет. */
    public function height(): int
    {
        return (int) $this->children->map(fn (self $child): int => $child->height() + 1)->max();
    }
}
```

```php
// app/Fastleo/CategoryResource.php
use App\Models\Category;
use Closure;
use Fastleo\Fields\BelongsTo;
use Illuminate\Database\Eloquent\Model;

protected static ?string $treeColumn = 'parent_id';

public function fields(): array
{
    return [
        Text::make('title', 'Название')->required(),
        BelongsTo::make('parent_id', 'Родитель')->hideInList()->rules(fn (?Model $record) => [
            function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                $parent = $value === null ? null : Category::find($value);

                if ($parent !== null && $parent->depth() + 1 + ($record?->height() ?? 0) > Category::MAX_DEPTH) {
                    $fail('Не больше '.Category::MAX_DEPTH.' уровней вложенности.');
                }
            },
        ]),
    ];
}
```

Подробнее — [дерево](../resources.md#дерево), [свои правила](../validation.md#свои-правила).

### Как задать ручной порядок внутри группы (фото товара, пункты одного меню)?

`$sortColumn` раздела нумерует всю таблицу, а при фильтре стрелки скрыты. Порядок внутри группы задайте одним из способов:

- **Записи одного родителя** (позиции, фото, цены) — блоки `HasMany` с `orderColumn('sort')` в форме родителя: порядок блоков перетаскивается.
- **Группы — сами записи раздела** (пункты меню, рубрики) — дерево: `$treeColumn` и `$sortColumn`, стрелки меняют порядок только среди соседей одной ветки.

```php
// app/Fastleo/MenuResource.php — фрагмент fields(): пункты одного меню
HasMany::make('items', 'Пункты')->fields([
    Text::make('title', 'Название')->required(),
    Url::make('url', 'Ссылка')->required(),
])->orderColumn('sort')->max(30),
```

```php
// app/Fastleo/CategoryResource.php: порядок внутри ветки дерева
protected static ?string $treeColumn = 'parent_id';
protected static ?string $sortColumn = 'sort';
```

Колонку `sort` (`$table->unsignedInteger('sort')->default(0);`) пишет пакет, в `$fillable` её не добавляют. Подробнее — [ручной порядок](../resources.md#ручной-порядок), [дерево](../resources.md#дерево), [HasMany](../fields/has-many.md).

## Импорт

### Как обновлять цены из файла по артикулу?

Включите импорт и задайте ключ `sku`. Файл с двумя столбцами «Артикул» и «Цена» обновит только цену у найденных товаров, остальные поля останутся как были.

```php
// app/Fastleo/ProductResource.php
protected static bool $importable = true;
protected static ?string $importKey = 'sku';

public function fields(): array
{
    return [
        Text::make('sku', 'Артикул')->required()
            ->rules(fn (?Model $record) => [Rule::unique('products', 'sku')->ignore($record)]),
        Text::make('name', 'Название')->required(),
        Number::make('price', 'Цена')->min(0)->step(0.01)->required(),
        Number::make('stock', 'Остаток')->integer()->min(0),
    ];
}
```

```
Артикул;Цена
A-100;1290
A-101;990.50
```

Строка с новым артикулом создаст товар (если обязательных столбцов в файле нет, строка пропустится с ошибкой в отчёте). Чтобы файл только обновлял и никогда не создавал товары, сделайте поле артикула `readonly()`: строки с неизвестным артикулом пропустятся (артикул новым товарам тогда ставит только код сайта или обмен с учётной системой). Миграция: `$table->string('sku', 64)->unique();`.

Подробнее — [новая запись или обновление](../import-export.md#новая-запись-или-обновление), [большие файлы и очередь](../import-export.md#большие-файлы-и-очередь).

## Кеш, адреса, история

### Как сбросить кеш сайта при сохранении записи?

Сбрасывайте ключ в observer модели после коммита: `saved` срабатывает и при перетаскивании порядка, `deleted` и `restored` — при удалении и возврате из корзины.

```php
// app/Observers/CategoryObserver.php
namespace App\Observers;

use App\Models\Category;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Cache;

final class CategoryObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Category $category): void
    {
        Cache::forget('menu.categories');
    }

    public function deleted(Category $category): void
    {
        Cache::forget('menu.categories');
    }

    public function restored(Category $category): void
    {
        Cache::forget('menu.categories');
    }
}
```

```php
// на сайте, например во View Composer
$categories = Cache::rememberForever('menu.categories', fn () => Category::query()->orderBy('sort')->get());
```

Кеш [настроек сайта](../settings.md#читать-настройки-на-сайте) (`fastleo_setting()`) пакет сбрасывает сам при сохранении страницы настроек. Подробнее — [события модели](../resources.md#события-модели).

### Как сделать адрес (slug) для каждого языка?

Поле `Slug` не бывает многоязычным: заведите по колонке на язык. Адрес основного языка строится из заголовка сам, остальные заполняются вручную; уникальность каждой колонки поле проверяет само.

```php
// миграция
$table->json('title');
$table->string('slug')->unique();
$table->string('slug_en')->nullable()->unique();
```

```php
// app/Fastleo/PageResource.php — фрагмент fields()
Text::make('title', 'Заголовок')->translatable()->required(),
Slug::make('slug', 'Адрес (RU)')->from('title'), // из основного языка
Slug::make('slug_en', 'Адрес (EN)'),
```

```php
// routes/web.php
Route::get('{locale}/{slug}', function (string $locale, string $slug) {
    $page = Page::query()->where($locale === 'en' ? 'slug_en' : 'slug', $slug)->firstOrFail();
    // …
})->whereIn('locale', ['ru', 'en']);
```

Уникальность заголовка в одном языке — правило `Rule::unique('pages', 'title->ru')`. Подробнее — [Slug](../fields/slug.md), [многоязычные поля](../translations.md#хранение), [свои правила](../validation.md#свои-правила).

### Как хранить историю версий и откатывать изменения?

Журнал изменений уже включён: кнопки «История» и «Вернуть» есть у каждой записи. Покажите историю рядом с формой и храните её дольше 30 дней.

```php
// app/Fastleo/ProductResource.php
protected static bool $historyPanel = true;
```

```php
// config/fastleo.php
'activity' => [
    'keep_days' => 365,
],
```

В журнал попадают только правки из админки; изменения из кода сайта, очереди и консоли нужно записывать своей таблицей. Подробнее — [откат](../activity.md#откат), [что попадает в журнал](../activity.md#что-попадает-в-журнал), [панель истории](../related.md#боковая-колонка-карточки).

## Удаление

### Как запретить удаление, если у записи есть связанные?

Запретите `delete` в Policy, когда связи есть, — кнопки «Удалить» у такой записи не будет. Внешний ключ с `restrictOnDelete()` защитит и от кода сайта.

```bash
php artisan fastleo:make-policy Category   # Policy со всеми методами, которые спрашивает админка
```

```php
// app/Policies/CategoryPolicy.php — замените метод delete
public function delete(User $user, Category $category): bool
{
    return ! $category->products()->exists();
}
```

```php
// миграция таблицы products
$table->foreignId('category_id')->constrained()->restrictOnDelete();
```

С Policy метода, которого в ней нет, админка не разрешит — поэтому создавайте Policy командой, а не с одним `delete`. Проверка идёт для каждой строки списка (один лёгкий запрос на строку). Если удаление всё же дошло до базы, админка покажет «запись связана с другими записями».

Подробнее — [права доступа](../authorization.md#с-policy), [ошибки базы данных](../validation.md#ошибки-базы-данных), [FAQ по правам](access.md).
