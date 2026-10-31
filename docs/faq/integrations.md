# FAQ: Интеграции

Заявки и вебхуки от других сервисов, телефония, уведомления в Telegram и Max, публикации в каналы, почта, обмен с 1С и маркетплейсами, S3, проверки состояния сайта. Все вопросы — [список](README.md).

В примерах модели `Lead`, `Call`, `Order`, `Product`, `Payment`; администратор — `App\Models\User`. Подставьте свои имена.

## Заявки и данные извне

### Как принимать заявки от другого сервиса по API?

Сделайте маршрут в `routes/api.php` (там нет проверки CSRF) с токеном в заголовке `Authorization: Bearer …`. Маршрут создаёт запись через модель — раздел админки покажет её сам.

```php
// routes/api.php (файл появляется после `php artisan install:api`)
use App\Models\Lead;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('leads', function (Request $request) {
    $token = (string) config('services.leads.token');
    abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

    $data = $request->validate([
        'name' => ['nullable', 'string', 'max:100'],
        'phone' => ['required', 'string', 'max:30'],
        'source' => ['nullable', 'string', 'max:50'],
    ]);
    $lead = Lead::create($data);

    Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body($lead->phone)
        ->url(route('fastleo.resources.show', ['leads', $lead->id], false))->icon('inbox')->level('success'));

    return response()->json(['id' => $lead->id], 201);
})->middleware('throttle:60,1');
```

```php
// config/services.php
'leads' => ['token' => env('LEADS_TOKEN')],
```

Проверьте маршрут:

```bash
curl -X POST https://site.ru/api/leads -H "Authorization: Bearer $LEADS_TOKEN" -H "Accept: application/json" -d phone=79001234567
```

Заявки с формы на этом же сайте — [FAQ по CRM](crm.md#как-принимать-заявки-с-формы-сайта-в-раздел-заявки). Подробнее — [рецепт](../recipes.md#заявки-с-сайта-и-из-телефонии).

### Как принять webhook с подписью (оплата, конструктор форм)?

Проверьте подпись тела запроса тем способом, который описан у сервиса; чаще всего это HMAC-SHA256 от «сырого» тела с секретом. Сравнивайте подпись через `hash_equals()`.

```php
// routes/api.php
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/payments', function (Request $request) {
    $secret = (string) config('services.payments.secret');
    $expected = hash_hmac('sha256', $request->getContent(), $secret);
    abort_if($secret === '' || ! hash_equals($expected, (string) $request->header('X-Signature')), 401);

    $data = $request->validate([
        'id' => ['required', 'string', 'max:64'],
        'order_number' => ['required', 'string', 'max:20'],
        'status' => ['required', 'in:paid,refunded'],
        'amount' => ['required', 'numeric'],
    ]);

    $payment = Payment::createOrFirst(['external_id' => $data['id']], [
        'order_number' => $data['order_number'],
        'status' => $data['status'],
        'amount' => $data['amount'],
    ]);

    $order = Order::query()->where('number', $payment->order_number)->first();
    $kopecks = fn (mixed $sum): int => (int) round((float) $sum * 100);

    // Оплаченным заказ становится, только если пришла его полная сумма.
    if ($payment->wasRecentlyCreated && $payment->status === 'paid' && $order !== null && $kopecks($payment->amount) === $kopecks($order->total)) {
        $order->update(['status' => OrderStatus::Paid]);
    }

    return response()->noContent();
})->middleware('throttle:120,1');
```

Имя заголовка и способ подписи у каждого сервиса свои — смотрите его документацию. Если сумма не совпала с `total` заказа, платёж сохранится, а заказ останется неоплаченным — менеджер разберётся по разделу платежей. Платежи показывайте разделом только для чтения (`protected static bool $readonly = true;`). Подробнее — [только просмотр](../resources.md#только-просмотр).

### Как не создавать дубль, если сервис прислал одно событие дважды?

Сохраняйте номер события из сервиса в колонку с уникальным индексом и создавайте запись через `createOrFirst()`. Даже два одновременных запроса создадут одну запись, а уведомление уйдёт один раз.

```php
// миграция
$table->string('external_id', 64)->unique();
```

```php
$lead = Lead::createOrFirst(['external_id' => $data['id']], ['phone' => $data['phone']]);

if ($lead->wasRecentlyCreated) {
    Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body($lead->phone));
}

return response()->json(['id' => $lead->id], $lead->wasRecentlyCreated ? 201 : 200);
```

Отвечайте сервису успехом и на повтор, иначе он будет присылать событие снова.

### Как принимать события телефонии (входящий и пропущенный звонок)?

Если АТС сама отправляет события на ваш адрес, сделайте маршрут-вебхук и раздел звонков только для чтения, который обновляется сам. Если телефония отдаёт звонки только по запросу — забирайте их командой по расписанию ([FAQ по CRM](crm.md#как-забирать-пропущенные-звонки-из-телефонии)).

```php
// routes/api.php
use App\Models\Call;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/calls', function (Request $request) {
    $token = (string) config('services.telephony.webhook_token');
    abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Token')), 401);

    $data = $request->validate([
        'call_id' => ['required', 'string', 'max:64'],
        'phone' => ['required', 'string', 'max:30'],
        'status' => ['required', 'in:answered,missed'],
        'duration' => ['nullable', 'integer', 'min:0'],
    ]);

    $call = Call::createOrFirst(['call_id' => $data['call_id']], $data);

    if ($call->wasRecentlyCreated && $call->status === 'missed') {
        Fastleo::notifyAdmins(FastleoMessage::make('Пропущенный звонок')->body($call->phone)
            ->url(route('fastleo.resources.show', ['calls', $call->id], false))->icon('phone-missed')->level('warning'));
    }

    return response()->noContent();
})->middleware('throttle:300,1');
```

```php
// app/Fastleo/CallResource.php
namespace App\Fastleo;

use App\Models\Call;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tel;
use Fastleo\Resource;

final class CallResource extends Resource
{
    protected static string $model = Call::class;
    protected static ?string $label = 'Звонки';
    protected static ?string $singular = 'Звонок';
    protected static string $icon = 'phone';
    protected static bool $readonly = true;
    protected static ?int $poll = 15;

    public function fields(): array
    {
        return [
            Tel::make('phone', 'Телефон')->searchable(),
            Select::make('status', 'Итог')->options(['answered' => 'Принят', 'missed' => 'Пропущен'])->badges(['answered' => 'success', 'missed' => 'danger']),
            Number::make('duration', 'Секунд')->integer(),
            DateTime::make('created_at', 'Когда')->onlyInList()->sortable(),
        ];
    }
}
```

Если АТС не умеет отправлять заголовки, передавайте токен параметром адреса (`?token=…`), но помните, что адрес попадает в логи веб-сервера. Подробнее — [автообновление списка](../list.md#автообновление-списка), [цветные метки](../fields/select.md).

### Как загружать цены и остатки из 1С или склада по расписанию?

Напишите команду: она забирает данные по API (или читает файл обмена), находит товары по артикулу и сохраняет через модель только изменившиеся. Запускайте её планировщиком.

```php
// app/Console/Commands/SyncStock.php
namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SyncStock extends Command
{
    protected $signature = 'stock:sync';

    protected $description = 'Цены и остатки из учётной системы';

    public function handle(): int
    {
        $rows = Http::withToken((string) config('services.exchange.token'))
            ->timeout(30)->retry(2, 1000)
            ->get(config('services.exchange.url').'/stock')
            ->throw()->json('items');

        $updated = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $products = Product::query()->whereIn('sku', array_column($chunk, 'sku'))->get()->keyBy('sku');

            foreach ($chunk as $row) {
                $product = $products->get($row['sku']);

                if ($product === null) {
                    continue;
                }

                $product->fill(['price' => $row['price'], 'stock' => $row['stock']]);

                if ($product->isDirty()) {
                    $product->save();
                    $updated++;
                }
            }
        }

        Cache::forever('stock:synced_at', now()->timestamp);
        $this->info("Обновлено товаров: {$updated}");

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('stock:sync')->hourly()->withoutOverlapping()->onOneServer();
```

Observers товара срабатывают (пересчёт цены со скидкой, сброс кеша), но в журнал изменений админки команда не пишет. Ручная загрузка файла по артикулу — [импорт](algorithms.md#как-обновлять-цены-из-файла-по-артикулу). Подробнее — [импорт](../import-export.md#импорт).

## Уведомления

### Как получать уведомления админки в Telegram или Max?

Создайте бота и добавьте его в разделе **Система → Уведомления**: всё, что уходит в колокольчик через `Fastleo::notifyAdmins()`, придёт и в выбранные чаты.

1. Создайте бота: в Telegram — у @BotFather, в Max — в разделе «Чат-боты» кабинета Max для партнёров. Скопируйте токен.
2. Напишите боту любое сообщение или добавьте его в рабочую группу.
3. В админке: **Уведомления → Создать**, выберите мессенджер, вставьте токен, нажмите «Найти чаты бота» и отметьте нужные чаты. В Max номер чата — только число.
4. Сохраните и запустите действие «Отправить тест».

Сообщения уходят задачами очереди (без очереди — сразу) с тремя попытками; сбой мессенджера не ломает сайт. Подробнее — [боты Telegram и Max](../notifications.md#боты-telegram-и-max).

### Как отправить уведомление в колокольчик и чаты из кода сайта?

Вызовите `Fastleo::notifyAdmins()` там, где произошло событие: в observer, контроллере, команде или задаче очереди.

```php
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;

Fastleo::notifyAdmins('Закончились остатки «Фильтр А-100»');

Fastleo::notifyAdmins(
    FastleoMessage::make('Возврат оплаты')
        ->body("Заказ №{$order->number}, {$order->total} ₽")
        ->url(route('fastleo.resources.show', ['orders', $order->id], false))
        ->icon('undo-2')
        ->level('warning'), // info, success, warning, danger
);
```

Ссылку задавайте относительной (`route(…, false)`) — так она откроется на любом домене админки. Подробнее — [отправить уведомление](../notifications.md#отправить-уведомление).

### Как уведомить одного администратора?

`Fastleo::notify($user, …)` кладёт уведомление в колокольчик только ему; в чаты ботов и на почту оно не уходит.

```php
use App\Models\User;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;

$user = User::query()->where('email', 'buh@site.ru')->first();

if ($user !== null) {
    Fastleo::notify($user, FastleoMessage::make('Готов отчёт за месяц')->url(route('fastleo.pages.show', 'sales', false))->icon('file-chart-column'));
}
```

Уведомление менеджеру о назначенной заявке — [FAQ по CRM](crm.md#как-уведомить-менеджера-в-колокольчик-когда-ему-назначили-заявку).

### Как слать уведомления только части администраторов?

Задайте получателей в `AppServiceProvider::boot()` — `notifyAdmins()` и копии на почту пойдут только им.

```php
// app/Providers/AppServiceProvider.php
use App\Models\User;
use Fastleo\Fastleo;

public function boot(): void
{
    Fastleo::resolveAdminsUsing(fn () => User::query()
        ->where('fastleo_admin', true)
        ->whereIn('role', ['owner', 'manager'])
        ->get());
}
```

Чаты ботов от этого не меняются: туда сообщение уходит один раз в каждый отмеченный чат. Подробнее — [кто получает уведомления](../notifications.md#кто-получает-уведомления).

### Как получать уведомления ещё и на почту?

Включите копию на почту: каждое `notifyAdmins()` уйдёт письмом всем администраторам с адресом.

```php
// config/fastleo.php
'notifications' => [
    'mail' => true,
],
```

На сайте должна быть настроена почта (`MAIL_*`, вопрос [ниже](#как-настроить-почту-для-писем-админки)) и лучше — очередь. Подробнее — [копия на почту](../notifications.md#копия-на-почту).

### Как сделать своё уведомление Laravel в колокольчик и на почту?

Опишите обычный класс `Notification` с каналом `fastleo` и методом `toFastleo()`, а отправьте через `Notification::send()`.

```php
// app/Notifications/OrderPaid.php
namespace App\Notifications;

use App\Models\Order;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderPaid extends Notification
{
    public function __construct(private readonly Order $order) {}

    public function via(object $notifiable): array
    {
        return ['fastleo', 'mail'];
    }

    public function toFastleo(object $notifiable): FastleoMessage
    {
        return FastleoMessage::make("Оплачен заказ №{$this->order->number}")->icon('wallet')->level('success');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Оплачен заказ №{$this->order->number}")->line("Сумма: {$this->order->total} ₽");
    }
}
```

```php
use App\Notifications\OrderPaid;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\Notification;

Notification::send(Fastleo::admins(), new OrderPaid($order));
```

Подробнее — [свой класс уведомления](../notifications.md#свой-класс-уведомления).

### Как сообщать администраторам о сбое фоновой задачи?

Добавьте задаче метод `failed()`: он вызывается, когда попытки закончились.

```php
// app/Jobs/ExportOrdersToWarehouse.php
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Throwable;

public int $tries = 3;

public function failed(?Throwable $exception): void
{
    Fastleo::notifyAdmins(FastleoMessage::make('Заказы не выгрузились на склад')
        ->body('Задача остановилась после 3 попыток. Подробности — в журнале ошибок.')
        ->url(route('fastleo.logs.index', absolute: false))
        ->level('danger'));
}
```

Не кладите в текст уведомления сообщение исключения: в нём бывают адреса, токены и куски SQL, а уведомление уходит и в чаты ботов. Подробнее — [журнал ошибок](../pages.md#журнал-ошибок).

## Публикации в каналы

### Как публиковать новости в канал Telegram или Max?

Добавьте канал в разделе **Система → Каналы**, а в ресурс новостей — действие `Publish`. Кнопка «Опубликовать в каналы» создаст черновик поста из записи; администратор правит его и нажимает «Отправить».

```php
// app/Fastleo/NewsResource.php
use App\Models\News;
use Fastleo\Publishing\Post;
use Fastleo\Publishing\Publish;

public function actions(): array
{
    return [
        Publish::make(fn (News $news): Post => Post::make("**{$news->title}**\n\n{$news->lead}")
            ->images([$news->photo])
            ->button('Читать на сайте', route('news.show', $news))),
    ];
}
```

Шаги для канала:

1. Создайте бота (Telegram — @BotFather, Max — кабинет Max для партнёров) и сделайте его администратором канала с правом публиковать, редактировать и удалять сообщения.
2. В админке: **Каналы → Создать**, вставьте токен, нажмите «Найти чаты бота» или впишите номер канала (`-100…` или `@имя` в Telegram, число в Max).
3. Сохраните — пакет проверит права бота в канале.

`route()` строит адрес от текущего запроса. Если админка открыта на отдельном домене (`admin.site.ru`), в пост попадёт её домен — стройте адрес от `APP_URL`: `->button('Читать на сайте', rtrim(config('app.url'), '/').route('news.show', $news, false))`. Подробнее — [каналы](../publishing.md#каналы), [кнопка в разделе сайта](../publishing.md#кнопка-в-разделе-сайта).

### Как отложить публикацию поста на нужное время?

В окне «Отправить» заполните поле «Отправить в». Поле появляется, только если на сайте настоящая очередь и запущен обработчик.

```bash
# .env
QUEUE_CONNECTION=database

# на сервере (supervisor или systemd)
php artisan queue:work
```

Отложенный пост можно отменить — он снова станет черновиком. Хостинг без постоянного процесса — [очередь на хостинге с одним cron](../installation.md#очередь-на-хостинге-с-одним-cron). Подробнее — [отложенная отправка](../publishing.md#отложенная-отправка).

### Можно ли публиковать в канал автоматически при сохранении новости?

Публикации пакета отправляет только человек кнопкой «Отправить»: пост проверяют перед выходом. Для автоматической отправки сделайте свою задачу очереди, которая вызывает Bot API мессенджера, и запускайте её командой по расписанию в момент публикации. Observer `created` для этого не годится: он отправил бы черновик и новость с будущей датой.

```php
// app/Console/Commands/PostNewsToChannel.php
namespace App\Console\Commands;

use App\Jobs\SendTelegramMessage;
use App\Models\News;
use Illuminate\Console\Command;

final class PostNewsToChannel extends Command
{
    protected $signature = 'news:post-to-channel';

    public function handle(): int
    {
        News::query()
            ->published() // включена и дата наступила — scope из FAQ по страницам
            ->whereNull('posted_at')
            ->lazyById(100)
            ->each(function (News $news): void {
                SendTelegramMessage::dispatch((string) config('services.telegram.channel'), $news->title."\n".route('news.show', $news));
                $news->forceFill(['posted_at' => now()])->save();
            });

        return self::SUCCESS;
    }
}

// routes/console.php
Schedule::command('news:post-to-channel')->everyMinute()->withoutOverlapping();
```

Миграция: `$table->timestamp('posted_at')->nullable();` — уже вышедшим новостям заполните колонку, иначе они уйдут в канал при первом запуске. В консоли `route()` строит адрес от `APP_URL`. Scope `published()` — [FAQ по страницам](pages.md#как-сделать-раздел-новости-с-анонсом-текстом-и-обложкой); как устроен запуск в момент публикации — [FAQ по алгоритмам](algorithms.md#как-выполнить-действие-в-момент-публикации-уведомить-сбросить-кеш).

Задача `SendTelegramMessage` — в [FAQ по CRM](crm.md#как-отправить-менеджеру-личное-сообщение-в-telegram); для канала передайте его номер (`-100…`). Такие сообщения не попадут в раздел «Публикации».

## Почта

### Как настроить почту для писем админки?

Заполните `MAIL_*` и `APP_URL` в `.env` и отправьте тестовое письмо. Почта нужна для «Забыли пароль?», копий уведомлений и писем сайта.

```bash
# .env
APP_URL=https://site.ru
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.yandex.ru
MAIL_PORT=465
MAIL_USERNAME=robot@site.ru
MAIL_PASSWORD=пароль-приложения
MAIL_FROM_ADDRESS=robot@site.ru
MAIL_FROM_NAME="${APP_NAME}"
```

```bash
php artisan config:clear
php artisan tinker --execute="Mail::raw('Проверка почты', fn (\$m) => \$m->to('you@site.ru')->subject('Тест'))"
```

Виджет «Состояние сервера» на дашборде предупреждает, если на боевом сайте почта пишется в лог. Письмо не приходит — [FAQ по входу](access.md#как-сбросить-пароль-администратору-если-письмо-не-приходит). Подробнее — [забыли пароль](../auth.md#забыли-пароль).

### Как отправить письмо клиенту кнопкой из админки?

Сделайте действие с правом `view`, которое ставит письмо в очередь. Закройте его от MCP: письма клиентам отправляет человек.

```php
// app/Mail/OrderInvoice.php
namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OrderInvoice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Счёт по заказу №{$this->order->number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.order-invoice'); // resources/views/mail/order-invoice.blade.php
    }
}
```

```php
// app/Fastleo/Actions/SendInvoice.php
namespace App\Fastleo\Actions;

use App\Mail\OrderInvoice;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

final class SendInvoice extends Action
{
    protected ?string $label = 'Отправить счёт';
    protected string $icon = 'mail';
    protected ?string $confirm = 'Отправить счёт на почту клиента?';
    protected string $ability = 'view';
    protected bool $mcp = false;

    public function handle(Collection $records, array $data): ActionResult
    {
        $sent = 0;

        foreach ($records as $order) {
            if (filled($order->email)) {
                Mail::to($order->email)->queue(new OrderInvoice($order));
                $sent++;
            }
        }

        return ActionResult::success("Писем отправлено: {$sent} из {$records->count()}");
    }
}
```

Заготовки: `php artisan make:mail OrderInvoice --markdown=mail.order-invoice` и `php artisan fastleo:make-action SendInvoice`. Подключите действие в `actions()` ресурса заказов. Подробнее — [своё действие](../actions.md#своё-действие), [права действия](../actions.md#права).

### Как дать в письме ссылку на запись в админке?

Стройте адрес через `Fastleo::mailUrl()`: он берёт домен из `APP_URL` (или `fastleo.domain`), а не из запроса — ссылка верна и в письме из очереди.

```php
use Fastleo\Fastleo;

$url = Fastleo::mailUrl('fastleo.resources.show', ['orders', $order->id]);
// https://site.ru/fastleo/orders/42
```

В `FastleoMessage::url()` абсолютный адрес не нужен: копия на почту сама достроит относительную ссылку от `APP_URL`. Подробнее — [копия на почту](../notifications.md#копия-на-почту).

## Выгрузки и обмен

### Как выгрузить товары для 1С или маркетплейса файлом?

Кнопка «Экспорт» в списке выгружает xlsx или csv с теми столбцами, что видны в разделе. Чтобы файл был в нужном виде, сделайте отдельный раздел-выгрузку той же модели: только нужные поля, только для просмотра.

```php
// app/Fastleo/ProductFeedResource.php
namespace App\Fastleo;

use App\Models\Product;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;

final class ProductFeedResource extends Resource
{
    protected static string $model = Product::class;
    protected static ?string $label = 'Выгрузка товаров';
    protected static ?string $slug = 'products-feed';
    protected static ?string $group = 'Выгрузки';
    protected static string $icon = 'file-down';
    protected static bool $readonly = true;
    protected static bool $activity = false;

    public function query(Builder $query): Builder
    {
        return $query->where('is_active', true)->withSum('orderItems as sold', 'quantity');
    }

    public function fields(): array
    {
        return [
            Text::make('sku', 'Артикул')->searchable(),
            Text::make('name', 'Наименование'),
            Number::make('price', 'Цена'),
            Number::make('stock', 'Остаток'),
            Number::make('sold', 'Продано')->fromQuery(),
        ];
    }
}
```

CSV выгружается в UTF-8 с BOM и разделителем «;» — открывается в русском Excel и подходит для загрузки в 1С. Подробнее — [экспорт](../import-export.md#экспорт), [вычисляемые столбцы](../list.md#вычисляемые-столбцы), [только просмотр](../resources.md#только-просмотр).

### Как отдавать маркетплейсу постоянную ссылку на фид?

Сделайте маршрут сайта, который отдаёт файл потоком, и закройте его токеном в адресе. Админка для этого не нужна.

```php
// routes/web.php
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('feeds/products.csv', function (Request $request) {
    $token = (string) config('services.feed.token');
    abort_if($token === '' || ! hash_equals($token, (string) $request->query('token')), 404);

    return response()->streamDownload(function (): void {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['sku', 'name', 'price', 'stock'], ';', escape: '');

        Product::query()->where('is_active', true)->lazyById(500)->each(function (Product $product) use ($out): void {
            fputcsv($out, [$product->sku, $product->name, $product->price, $product->stock], ';', escape: '');
        });

        fclose($out);
    }, 'products.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
})->middleware('throttle:10,1');
```

Ссылка для маркетплейса: `https://site.ru/feeds/products.csv?token=…`. Для XML-фида (YML) отдайте Blade-шаблон с заголовком `Content-Type: application/xml`. Если фид большой и запрашивается часто, собирайте файл командой по расписанию в `Storage` и отдавайте готовый.

## Файлы и хранилище

### Как хранить файлы админки в S3 или Cloudflare R2?

Установите драйвер S3, заполните диск `s3` в `.env` и укажите его в настройках пакета. Менеджер файлов, картинки и их варианты начнут работать с этим диском.

```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

```bash
# .env — Cloudflare R2
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=auto
AWS_BUCKET=site-uploads
AWS_ENDPOINT=https://<id-аккаунта>.r2.cloudflarestorage.com
AWS_URL=https://cdn.site.ru
```

```php
// config/fastleo.php
'files' => [
    'disk' => 's3',
    'root' => 'uploads',
],
```

`AWS_URL` — публичный адрес бакета (свой домен или адрес `r2.dev`): по нему сайт и админка показывают файлы. Для Amazon S3 `AWS_ENDPOINT` не нужен, регион — ваш (`eu-central-1`). Уже загруженные файлы перенесите в бакет сами с теми же путями. Подробнее — [настройка файлов](../files.md#настройка), [ключи `files.*`](../configuration.md#файлы).

### Как вывести на сайте файл или картинку, если файлы лежат в S3?

В колонке хранится путь на диске (`uploads/products/a-100.jpg`), адрес даёт `Storage::disk()->url()`. Для картинок с вариантами используйте `<x-fastleo::picture>` — он строит адреса сам.

```blade
{{-- файл: инструкция к товару --}}
<a href="{{ Storage::disk(config('fastleo.files.disk'))->url($product->manual) }}">Инструкция (PDF)</a>

{{-- картинка с WebP-вариантами из images.presets --}}
<x-fastleo::picture :src="$product->photo" preset="card" :alt="$product->name" sizes="(min-width: 768px) 400px, 100vw" />
```

Подробнее — [картинки для сайта](../files.md#картинки-для-сайта-варианты-и-x-fastleopicture).

## Состояние сайта

### Как добавить свою проверку состояния сайта (обмен с 1С, заявки)?

Зарегистрируйте проверку в `AppServiceProvider::boot()`. Она видна в виджете «Состояние сервера» на дашборде и, на подключённом сайте, в отчёте для SoftNetica.

```php
// app/Providers/AppServiceProvider.php
use App\Models\Lead;
use Fastleo\Fastleo;
use Fastleo\Health\HealthResult;
use Illuminate\Support\Facades\Cache;

public function boot(): void
{
    Fastleo::healthCheck('stock-sync', 'Обмен с 1С', function (): HealthResult {
        $syncedAt = Cache::get('stock:synced_at'); // ставит команда stock:sync

        return is_int($syncedAt) && time() - $syncedAt < 2 * 3600
            ? HealthResult::ok()
            : HealthResult::failed('Остатки не обновлялись больше 2 часов');
    });

    Fastleo::healthCheck('leads', 'Заявки', fn (): HealthResult => Lead::query()->where('created_at', '>', now()->subDays(3))->exists()
        ? HealthResult::ok()
        : HealthResult::warning('Заявок нет уже 3 дня'));
}
```

Проверка запускается при каждом открытии дашборда — делайте её быстрой (индекс по `created_at`, без запросов к внешним сервисам). В тексте не пишите адресов, паролей и путей. Подробнее — [свои проверки сайта](../softnetica.md#свои-проверки-сайта), [ServerStatus](../dashboard.md#serverstatus--состояние-сервера).

### Как следить, что очередь и планировщик работают?

Смотрите виджет «Состояние сервера» на дашборде: он показывает задачи в очереди, упавшие задачи и время последнего запуска планировщика. Те же предупреждения выводит `php artisan fastleo:doctor`.

```bash
# cron на сервере
* * * * * cd /var/www/site && php artisan schedule:run >> /dev/null 2>&1

php artisan fastleo:doctor     # предупредит про очередь sync, retry_after, планировщик
php artisan queue:failed       # упавшие задачи
```

Подробнее — [ServerStatus](../dashboard.md#serverstatus--состояние-сервера), [fastleo:doctor](../claude.md#fastleodoctor), [очередь на хостинге с одним cron](../installation.md#очередь-на-хостинге-с-одним-cron).

### Как получить описание разделов в JSON для своих скриптов?

`php artisan fastleo:inspect --json` отдаёт разделы, поля, правила, связи, действия и Policy. Его удобно сверять в CI или выгружать в документацию проекта.

```bash
php artisan fastleo:inspect --json > storage/app/admin-schema.json
php artisan fastleo:inspect --json | jq '.resources[] | {slug, policy, readonly}'
php artisan fastleo:inspect orders --json    # один раздел
```

Подробнее — [fastleo:inspect](../claude.md#fastleoinspect).

## MCP

### Как подключить MCP-сервер?

Добавьте сервер в `.mcp.json` в корне сайта от имени администратора. Разделы с чужими данными закройте `protected static bool $mcp = false;`, удаление включается только ключом `--allow-delete`.

```json
{
  "mcpServers": {
    "fastleo": {
      "command": "php",
      "args": ["artisan", "fastleo:mcp", "--user=admin@site.ru"]
    }
  }
}
```

Подробнее — [MCP-сервер](../claude.md#mcp-сервер).
