# FAQ: CRM — клиенты, заявки, менеджеры, задачи

Клиенты, заявки, воронка, задачи, комментарии, уведомления и отчёты по менеджерам — готовые решения с кодом. Все вопросы — [список](README.md).

В примерах модели `Client`, `ClientPhone`, `Lead`, `Task`, `Order`; менеджеры — администраторы админки (модель `App\Models\User`, связь `Lead::manager()`). Подставьте свои имена.

## Клиенты

### Как сделать раздел «Клиенты» с несколькими телефонами?

Храните телефоны в отдельной таблице и выведите их в форме клиента блоками `HasMany`. Номер приводите к одному виду мутатором модели — так он одинаков из формы, импорта и кода сайта.

```bash
php artisan make:model Client -m                # модель и миграция clients, если их ещё нет
php artisan make:model ClientPhone -m           # модель и миграция client_phones
php artisan fastleo:make-resource Client        # app/Fastleo/ClientResource.php
```

```php
// database/migrations/2026_01_01_000001_create_client_phones_table.php
Schema::create('client_phones', function (Blueprint $table) {
    $table->id();
    $table->foreignId('client_id')->constrained()->cascadeOnDelete();
    $table->string('phone', 20)->index();
    $table->timestamps();
});
```

```php
// app/Models/ClientPhone.php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'phone'])]
class ClientPhone extends Model
{
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** 8 (900) 123-45-67 и +7 900 123 45 67 → 79001234567 */
    public static function normalize(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return strlen($digits) === 11 && $digits[0] === '8' ? '7'.substr($digits, 1) : $digits;
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalize($value));
    }
}
```

```php
// app/Fastleo/ClientResource.php
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;

public function fields(): array
{
    return [
        Text::make('name', 'Имя')->required()->searchable()->sortable(),
        HasMany::make('phones', 'Телефоны')->fields([
            Tel::make('phone', 'Телефон')->required(),
        ])->max(10),
    ];
}
```

У модели `Client` нужен метод `phones(): HasMany`. Подробнее — [HasMany](../fields/has-many.md), [рецепт](../recipes.md#несколько-телефонов-у-клиента).

### Как найти клиента по любому из его номеров?

Держите в таблице клиентов колонку со всеми номерами через пробел, обновляйте её observer'ом телефона и отметьте её `searchable()`.

```bash
php artisan make:migration add_phones_digits_to_clients_table
php artisan make:observer ClientPhoneObserver --model=ClientPhone   # app/Observers/ClientPhoneObserver.php
```

```php
// database/migrations/…_add_phones_digits_to_clients_table.php
$table->string('phones_digits', 500)->default('');

// app/Observers/ClientPhoneObserver.php
namespace App\Observers;

use App\Models\ClientPhone;

final class ClientPhoneObserver
{
    public function saved(ClientPhone $phone): void
    {
        $this->refresh($phone);
    }

    public function deleted(ClientPhone $phone): void
    {
        $this->refresh($phone);
    }

    private function refresh(ClientPhone $phone): void
    {
        $client = $phone->client;
        $client?->forceFill(['phones_digits' => $client->phones()->pluck('phone')->implode(' ')])->saveQuietly();
    }
}

// app/Models/ClientPhone.php
#[ObservedBy([ClientPhoneObserver::class])]
class ClientPhone extends Model { /* … */ }

// app/Fastleo/ClientResource.php
Text::make('phones_digits', 'Номера')->onlyInList()->searchable(),
```

В строке поиска набирайте цифры: `9001234567` найдёт `79001234567`. Подробнее — [рецепт](../recipes.md#несколько-телефонов-у-клиента), [поиск](../list.md#поиск).

### Как не завести клиента-дубль по телефону?

Сделайте основной номер уникальным и добавьте `duplicateOf()` — под полем появится ссылка «Открыть: Иванов Иван» на уже существующего клиента.

```php
// app/Fastleo/ClientResource.php
use App\Models\Client;
use Fastleo\Fields\Tel;
use Illuminate\Validation\Rule;

Tel::make('phone', 'Основной телефон')->required()
    ->rules(fn (?Client $record) => [Rule::unique('clients', 'phone')->ignore($record)])
    ->duplicateOf(fn (string $phone) => Client::query()->where('phone', $phone)->first()),
```

Подробнее — [общие методы полей](../fields/README.md#общие-методы), [рецепт](../recipes.md#несколько-телефонов-у-клиента).

### Как из заявки открыть клиента с тем же номером или создать нового?

Добавьте разделу заявок действие: оно ищет клиента по номеру и ведёт в его карточку, а если клиента нет — в форму создания с подставленными именем и телефоном.

```bash
php artisan fastleo:make-action OpenClient      # app/Fastleo/Actions/OpenClient.php
```

```php
// app/Fastleo/Actions/OpenClient.php
namespace App\Fastleo\Actions;

use App\Models\ClientPhone;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;

final class OpenClient extends Action
{
    protected ?string $label = 'Открыть клиента';

    protected string $icon = 'user';

    protected string $ability = 'view';

    protected bool $bulk = false;

    public function handle(Collection $records, array $data): ActionResult
    {
        $lead = $records->firstOrFail();
        $client = ClientPhone::query()->where('phone', ClientPhone::normalize($lead->phone))->first()?->client;

        return ActionResult::redirect($client
            ? route('fastleo.resources.show', ['clients', $client->id], false)
            : route('fastleo.resources.create', ['resource' => 'clients', 'prefill' => ['name' => (string) $lead->name, 'phone' => (string) $lead->phone]], false));
    }
}

// app/Fastleo/LeadResource.php
use App\Fastleo\Actions\OpenClient;

public function actions(): array
{
    return [new OpenClient];
}
```

Подставляются только простые поля формы (здесь — `name` и основной `phone`), блоки `HasMany` — нет. Подробнее — [своё действие](../actions.md#своё-действие), [форма из ссылки](../layout.md#форма-создания-заполненная-из-ссылки).

### Как показать в карточке клиента машины, заказы, задачи и историю справа?

Перечислите связанные таблицы в `relations()`: широкие — под формой, короткие — `aside()` в правой колонке; панель истории включается свойством `$historyPanel`.

```php
// app/Fastleo/ClientResource.php
use Fastleo\Related;

protected static bool $historyPanel = true;

public function relations(): array
{
    return [
        Related::make('cars', CarResource::class)->label('Машины'),
        Related::make('orders', OrderResource::class)->label('Заказы')->perPage(10),
        Related::make('tasks', TaskResource::class)->aside()->columns(['title', 'due_at']),
    ];
}
```

Первый аргумент — метод `HasMany` модели (`Client::cars()`), а в форме дочернего раздела должно быть поле `BelongsTo::make('client_id', 'Клиент')`. Проверьте `php artisan fastleo:doctor`. Подробнее — [связанные таблицы](../related.md), [боковая колонка](../related.md#боковая-колонка-карточки).

### Как сделать вложенные уровни: заказ → позиции → предложения?

Включите `expandable()` у связанной таблицы и опишите `relations()` у каждого дочернего раздела — строка раскрывается стрелкой прямо в карточке.

```php
// app/Fastleo/ClientResource.php
Related::make('orders', OrderResource::class)->expandable(),

// app/Fastleo/OrderResource.php
public function relations(): array
{
    return [Related::make('items', OrderItemResource::class)->expandable()];
}

// app/Fastleo/OrderItemResource.php
public function relations(): array
{
    return [Related::make('offers', OfferResource::class)];
}
```

Подробнее — [раскрывающиеся строки](../related.md#раскрывающиеся-строки).

### Как показать в списке клиентов число заявок и сумму покупок?

Посчитайте значения в `query()` ресурса и выведите полями с `fromQuery()`.

```php
// app/Fastleo/ClientResource.php
use Fastleo\Fields\Number;
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query->withCount('leads')->withSum('orders as revenue', 'total');
}

// в fields()
Number::make('leads_count', 'Заявок')->fromQuery()->sortable(),
Number::make('revenue', 'Покупок, ₽')->fromQuery()->sortable()
    ->formatUsing(fn ($value) => number_format((float) $value, 0, ',', ' ')),
```

Подробнее — [вычисляемые столбцы](../list.md#вычисляемые-столбцы).

## Заявки и воронка

### Как принимать заявки с формы сайта в раздел «Заявки»?

Сделайте маршрут сайта с токеном: он создаёт запись и сообщает администраторам. Раздел `LeadResource` покажет её сам.

```php
// routes/api.php (после `php artisan install:api`)
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
        'text' => ['nullable', 'string', 'max:2000'],
    ]);
    $lead = Lead::create($data + ['source' => 'site']);

    Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body($lead->phone)
        ->url(route('fastleo.resources.show', ['leads', $lead->id], false))->icon('inbox')->level('success'));

    return response()->json(['id' => $lead->id], 201);
})->middleware('throttle:60,1');
```

```php
// config/services.php
'leads' => ['token' => env('LEADS_TOKEN')],
```

Если форма стоит на этом же сайте, вызывайте `Lead::create()` прямо из её контроллера — токен не нужен. Подробнее — [рецепт](../recipes.md#заявки-с-сайта-и-из-телефонии), [API и вебхуки](integrations.md).

### Как забирать пропущенные звонки из телефонии?

Напишите команду, которая забирает звонки по API телефонии, и запускайте её по расписанию.

```bash
php artisan make:command ImportMissedCalls --command=leads:import-missed-calls   # app/Console/Commands/ImportMissedCalls.php
```

```php
// app/Console/Commands/ImportMissedCalls.php
namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ImportMissedCalls extends Command
{
    protected $signature = 'leads:import-missed-calls';

    public function handle(): int
    {
        $calls = Http::withToken((string) fastleo_setting('telephony.token'))
            ->timeout(10)->retry(2, 500)
            ->get(config('services.telephony.url').'/lost-calls', ['minutes' => 10])
            ->throw()->json();

        foreach ($calls as $call) {
            Lead::firstOrCreate(['call_id' => $call['id']], ['phone' => $call['phone'], 'source' => 'call']);
        }

        return self::SUCCESS;
    }
}

// routes/console.php
Schedule::command('leads:import-missed-calls')->everyTenMinutes()->withoutOverlapping()->onOneServer();
```

`call_id` с уникальным индексом не даст завести звонок дважды. Токен телефонии храните на [странице настроек](../settings.md#секреты) текстовым полем и перечислите его в `encrypted()` — в базе он будет зашифрован; планировщику нужен cron `* * * * * php artisan schedule:run`. Подробнее — [рецепт](../recipes.md#заявки-с-сайта-и-из-телефонии).

### Как показать статусы воронки цветными метками?

Опишите статусы enum с методами `label()` и `tone()` и выведите полем `Enum` с `badges()`.

```bash
php artisan make:enum App/Enums/LeadStatus --string        # app/Enums/LeadStatus.php
php artisan make:migration add_status_to_leads_table
```

```php
// app/Enums/LeadStatus.php
namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case InWork = 'in_work';
    case Offer = 'offer';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InWork => 'В работе',
            self::Offer => 'Предложение',
            self::Won => 'Продано',
            self::Lost => 'Отказ',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InWork, self::Offer => 'warning',
            self::Won => 'success',
            self::Lost => 'neutral',
        };
    }
}
```

```php
// database/migrations/…_add_status_to_leads_table.php
$table->string('status', 20)->default('new')->index();

// app/Models/Lead.php
protected function casts(): array
{
    return ['status' => LeadStatus::class];
}

// app/Fastleo/LeadResource.php
use App\Enums\LeadStatus;
use Fastleo\Fields\Enum;
use Fastleo\Filters\SelectFilter;

Enum::make('status', 'Статус')->enum(LeadStatus::class)->required()->badges()->sortable(),

public function filters(): array
{
    return [SelectFilter::make('status')];
}
```

Подробнее — [Enum](../fields/enum.md), [цветные метки](../fields/select.md#цветные-метки).

### Как менять статус заявки прямо в списке?

Добавьте полю статуса `inlineEditable()` — щелчок по ячейке открывает выбор, значение сохраняется сразу.

```php
// app/Fastleo/LeadResource.php
Enum::make('status', 'Статус')->enum(LeadStatus::class)->required()->badges()->inlineEditable(),
```

Права и проверки — как при сохранении формы. Подробнее — [правка в ячейке](../list.md#правка-в-ячейке).

### Как назначить заявке менеджера?

Добавьте в заявку колонку `manager_id`, связь `manager()` и поле `BelongsTo` с вариантами только из администраторов.

```bash
php artisan make:migration add_manager_id_to_leads_table
```

```php
// database/migrations/…_add_manager_id_to_leads_table.php
$table->foreignId('manager_id')->nullable()->index()->constrained('users')->nullOnDelete();

// app/Models/Lead.php
public function manager(): BelongsTo
{
    return $this->belongsTo(User::class, 'manager_id');
}

// app/Fastleo/LeadResource.php
use Fastleo\Fields\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

BelongsTo::make('manager_id', 'Менеджер')
    ->query(fn (Builder $query) => $query->where('fastleo_admin', true))
    ->inlineEditable(),
```

`manager_id` добавьте в `$fillable` модели. Кто может менять менеджера — решает Policy ([права](access.md)). Подробнее — [BelongsTo](../fields/belongs-to.md).

### Как открывать «только мои заявки» по умолчанию?

Задайте фильтру менеджера значение по умолчанию — текущего администратора. Фильтру нужно поле `BelongsTo` с тем же именем.

```php
// app/Fastleo/LeadResource.php
use Fastleo\Fastleo;
use Fastleo\Filters\BelongsToFilter;

public function filters(): array
{
    return [
        BelongsToFilter::make('manager_id', 'Менеджер')->default(fn () => Fastleo::guard()->id()),
        SelectFilter::make('status'),
    ];
}
```

Менеджер может выбрать «Все» — это удобство, а не защита. Чтобы чужие заявки были не видны совсем, ограничьте `query()` ресурса ([рецепт](../recipes.md#только-свои-записи), [права](access.md)). Подробнее — [фильтры](../list.md#фильтры).

### Как сделать кнопку «Взять в работу», чтобы двое не взяли одну заявку?

Сделайте действие с условным обновлением: заявка достаётся тому, кто успел первым. Отдельный метод Policy `take` решает, кто вообще может брать заявки (даже без права их менять), а «ещё ничья» проверяет само действие: на устаревшей странице менеджер увидит «Заявку уже взял другой менеджер», а не ошибку 403.

```bash
php artisan fastleo:make-action TakeLead        # app/Fastleo/Actions/TakeLead.php
php artisan fastleo:make-policy Lead            # app/Policies/LeadPolicy.php, если её ещё нет
```

```php
// app/Fastleo/Actions/TakeLead.php
namespace App\Fastleo\Actions;

use App\Models\Lead;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class TakeLead extends Action
{
    protected ?string $label = 'Взять в работу';

    protected string $icon = 'hand';

    protected string $ability = 'take';

    public function visibleFor(Model $record): bool
    {
        return $record->manager_id === null;
    }

    public function handle(Collection $records, array $data): ActionResult
    {
        $taken = 0;

        foreach ($records as $lead) {
            $taken += Lead::query()->whereKey($lead->getKey())->whereNull('manager_id')
                ->update(['manager_id' => Fastleo::guard()->id(), 'status' => 'in_work']);
        }

        return $taken === 0
            ? ActionResult::error('Заявку уже взял другой менеджер.')
            : ActionResult::success("Взято: {$taken} из {$records->count()}");
    }
}

// app/Policies/LeadPolicy.php
public function take(User $user, Lead $lead): bool
{
    return in_array($user->role, ['admin', 'manager'], true);
}

// app/Fastleo/LeadResource.php
use App\Fastleo\Actions\TakeLead;

public function actions(): array
{
    return [new TakeLead];
}
```

Прямой `update()` не вызывает observers и не пишет журнал изменений. Если они нужны, берите заявку в транзакции через `lockForUpdate()` и сохраняйте моделью. Подробнее — [рецепт](../recipes.md#взять-в-работу-без-гонки), [права действий](../actions.md#права).

### Как сделать «Входящие», которые обновляются сами?

Задайте разделу `$poll` и поставьте невзятые заявки наверх в `query()` — только пока не выбрана сортировка по столбцу, иначе клик по заголовку не изменит порядок.

```php
// app/Fastleo/LeadResource.php
use Illuminate\Database\Eloquent\Builder;

protected static ?int $poll = 10; // секунд, от 5 до 3600

public function query(Builder $query): Builder
{
    return request()->filled('sort') ? $query : $query->orderByRaw('manager_id is not null')->latest();
}
```

Обновление не сбивает выбранные записи и набранный текст. Подробнее — [автообновление списка](../list.md#автообновление-списка).

### Как открывать заявки сразу после входа в админку?

Укажите адрес раздела в `home`; пункт «Дашборд» можно убрать.

```php
// config/fastleo.php
'home' => 'leads',
'menu' => [
    'dashboard' => false,
],
```

Проверьте адрес командой `php artisan fastleo:doctor` — опечатку она покажет. Подробнее — [меню и стартовая страница](../configuration.md#меню).

### Как подсветить просроченные заявки и задачи?

Верните тон строки из `rowTone()` ресурса.

```php
// app/Fastleo/TaskResource.php
use Illuminate\Database\Eloquent\Model;

public function rowTone(Model $record): ?string
{
    return match (true) {
        $record->is_done => 'success',
        $record->due_at?->isPast() => 'danger',
        default => null,
    };
}

// app/Fastleo/LeadResource.php — новая заявка ждёт больше часа
public function rowTone(Model $record): ?string
{
    return $record->manager_id === null && $record->created_at->lt(now()->subHour()) ? 'danger' : null;
}
```

У `due_at` нужен каст `datetime`, у `is_done` — `boolean`. Цвет виден и в связанных таблицах карточки. Подробнее — [подсветка строк](../list.md#подсветка-строк).

### Как показать в меню число новых заявок и просроченных задач?

Верните число из статического `badge()` ресурса; `null` — без числа.

```php
// app/Fastleo/LeadResource.php
public static function badge(): ?int
{
    return Lead::query()->whereNull('manager_id')->count() ?: null;
}

// app/Fastleo/TaskResource.php
use Fastleo\Fastleo;

public static function badge(): ?int
{
    return Task::query()->where('is_done', false)->where('due_at', '<', now())
        ->where('manager_id', Fastleo::guard()->id())->count() ?: null;
}
```

Метод вызывается на каждой странице админки — нужны индексы на `manager_id`, `due_at`. Подробнее — [badge()](../resources.md#badge), [рецепт](../recipes.md#счётчики-в-меню-и-порядок-групп).

### Как выделять непросмотренные заявки и считать в меню только их?

Добавьте колонку `read_at` и укажите её в ресурсе. Открыли заявку — она просмотрена для всех; в меню число непросмотренных, в списке они жирные. Свой `badge()` для этого не нужен.

```bash
php artisan make:migration add_read_at_to_leads_table
```

```php
// database/migrations/…_add_read_at_to_leads_table.php
Schema::table('leads', function (Blueprint $table) {
    $table->timestamp('read_at')->nullable()->index();
});

// app/Fastleo/LeadResource.php
protected static ?string $readColumn = 'read_at';
```

Случайно открыли — действие «Не просмотрено» вернёт заявку в новые; «Просмотрено» над выбранными отмечает сразу много. Подробнее — [отметка «просмотрено»](../resources.md#отметка-просмотрено).

## Задачи, комментарии, файлы, история

### Как добавить к клиенту задачи с быстрым добавлением?

Сделайте раздел задач и подключите его к клиенту связанной таблицей с `quickAdd()` — маленькая форма появится прямо под таблицей.

```bash
php artisan make:model Task -m                  # модель и миграция tasks
php artisan fastleo:make-resource Task          # app/Fastleo/TaskResource.php
```

```php
// database/migrations/…_create_tasks_table.php
Schema::create('tasks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('client_id')->constrained()->cascadeOnDelete();
    $table->foreignId('manager_id')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->string('title');
    $table->dateTime('due_at')->nullable()->index();
    $table->boolean('is_done')->default(false);
    $table->dateTime('reminded_at')->nullable();
    $table->timestamps();
});
```

```php
// app/Fastleo/TaskResource.php
namespace App\Fastleo;

use App\Models\Task;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;

final class TaskResource extends Resource
{
    protected static string $model = Task::class;

    protected static ?string $label = 'Задачи';

    protected static ?string $singular = 'Задача';

    protected static string $icon = 'list-checks';

    public function fields(): array
    {
        return [
            Text::make('title', 'Задача')->required()->searchable(),
            BelongsTo::make('client_id', 'Клиент')->required(),
            BelongsTo::make('manager_id', 'Ответственный'),
            DateTime::make('due_at', 'Срок')->sortable(),
            Toggle::make('is_done', 'Выполнена')->quickToggle(),
        ];
    }
}

// app/Fastleo/ClientResource.php
Related::make('tasks', TaskResource::class)
    ->aside()
    ->columns(['title', 'due_at', 'is_done'])
    ->quickAdd(['title', 'due_at', 'manager_id'])
    ->addLabel('Добавить задачу'),
```

У модели `Task` нужны связи `client()` и `manager()` (`belongsTo(User::class, 'manager_id')`), касты `'due_at' => 'datetime'`, `'is_done' => 'boolean'` и все поля формы в `$fillable`. Клиент в быстром добавлении подставляется сам. Подробнее — [быстрое добавление](../related.md#быстрое-добавление).

### Как напомнить менеджеру о задаче в срок?

Запускайте по расписанию команду: она находит задачи с наступившим сроком и кладёт уведомление в колокольчик ответственного.

```bash
php artisan make:command RemindTasks --command=tasks:remind   # app/Console/Commands/RemindTasks.php
```

```php
// app/Console/Commands/RemindTasks.php
namespace App\Console\Commands;

use App\Models\Task;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Console\Command;

final class RemindTasks extends Command
{
    protected $signature = 'tasks:remind';

    public function handle(): int
    {
        Task::query()->with('manager')
            ->where('is_done', false)->whereNull('reminded_at')
            ->whereNotNull('manager_id')->where('due_at', '<=', now())
            ->lazyById(500)
            ->each(function (Task $task): void {
                Fastleo::notify($task->manager, FastleoMessage::make('Срок задачи: '.$task->title)
                    ->url(route('fastleo.resources.show', ['tasks', $task->id], false))
                    ->icon('alarm-clock')->level('warning'));

                $task->forceFill(['reminded_at' => now()])->saveQuietly();
            });

        return self::SUCCESS;
    }
}

// routes/console.php
Schedule::command('tasks:remind')->everyFiveMinutes()->withoutOverlapping();
```

Колонка `reminded_at` не даёт напомнить дважды. `lazyById()` перебирает задачи по ключу: цикл меняет `reminded_at`, по которому идёт отбор, и постраничный перебор пропустил бы часть записей. Подробнее — [уведомления](../notifications.md#отправить-уведомление).

### Как отметить задачу выполненной одним кликом?

Добавьте галочке `quickToggle()`, а для нескольких задач сразу — действие `SetToggle`.

```php
// app/Fastleo/TaskResource.php
use Fastleo\Actions\SetToggle;
use Fastleo\Fields\Toggle;

Toggle::make('is_done', 'Выполнена')->quickToggle(),

public function actions(): array
{
    return [
        SetToggle::make('is_done', 'Выполнено', true)->icon('check'),
        SetToggle::make('is_done', 'Вернуть в работу', false)->icon('rotate-ccw'),
    ];
}
```

Подробнее — [быстрый переключатель](../fields/toggle.md#быстрый-переключатель-в-списке), [SetToggle](../actions.md#готовое-действие-показать--скрыть).

### Как добавить комментарии к заявке?

Сделайте раздел комментариев и выведите его в карточке заявки справа с быстрым добавлением. Автора ставит observer, а не поле формы.

```bash
php artisan make:model LeadComment -m                                # модель и миграция lead_comments
php artisan fastleo:make-resource LeadComment                        # app/Fastleo/LeadCommentResource.php
php artisan make:observer LeadCommentObserver --model=LeadComment    # app/Observers/LeadCommentObserver.php
```

```php
// database/migrations/…_create_lead_comments_table.php
Schema::create('lead_comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
    $table->text('body');
    $table->timestamps();
});

// app/Observers/LeadCommentObserver.php
namespace App\Observers;

use App\Models\LeadComment;
use Fastleo\Fastleo;

final class LeadCommentObserver
{
    public function creating(LeadComment $comment): void
    {
        $comment->author_id ??= Fastleo::guard()->id();
    }
}
```

```php
// app/Fastleo/LeadCommentResource.php
namespace App\Fastleo;

use App\Models\LeadComment;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;

final class LeadCommentResource extends Resource
{
    protected static string $model = LeadComment::class;

    protected static ?string $label = 'Комментарии';

    protected static ?string $singular = 'Комментарий';

    public static function inMenu(): bool
    {
        return false; // только в карточке заявки
    }

    public function fields(): array
    {
        return [
            Textarea::make('body', 'Комментарий')->required()->showInList(),
            BelongsTo::make('lead_id', 'Заявка')->required(),
            BelongsTo::make('author_id', 'Автор')->readonly(),
            DateTime::make('created_at', 'Когда')->onlyInList(),
        ];
    }
}

// app/Fastleo/LeadResource.php
public function relations(): array
{
    return [
        Related::make('comments', LeadCommentResource::class)
            ->aside()
            ->columns(['body', 'author_id', 'created_at'])
            ->quickAdd(['body'])
            ->addLabel('Добавить комментарий'),
    ];
}
```

У модели `LeadComment` нужны связи `lead()` и `author()` (`belongsTo(User::class, 'author_id')`) и атрибут `#[ObservedBy([LeadCommentObserver::class])]`, у `Lead` — `comments(): HasMany`. Кто может править и удалять чужие комментарии — Policy `LeadCommentPolicy` ([права](access.md)). Подробнее — [быстрое добавление](../related.md#быстрое-добавление), [inMenu()](../resources.md#inmenu).

### Как прикрепить к заявке файлы?

Добавьте блоки `HasMany` с полем `File` внутри — у каждой заявки будет своя папка файлов.

```bash
php artisan make:model LeadFile -m              # модель и миграция lead_files
```

```php
// database/migrations/…_create_lead_files_table.php
Schema::create('lead_files', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    $table->string('path');
    $table->string('title')->nullable();
    $table->timestamps();
});

// app/Fastleo/LeadResource.php
use Fastleo\Fields\File;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;

HasMany::make('files', 'Файлы')->fields([
    File::make('path', 'Файл')->directory('leads')->extensions(['pdf', 'jpg', 'png', 'docx', 'xlsx'])->required(),
    Text::make('title', 'Название'),
])->max(20),
```

Нужен метод `Lead::files(): HasMany` и `$fillable` у `LeadFile`. Одну и ту же связь не выводите сразу полем и в `relations()` ([почему](../related.md#не-показывайте-связь-дважды)). Подробнее — [рецепт](../recipes.md#файлы-заявки), [файлы](../files.md).

### Как посмотреть, кто и что менял в заявке?

Включите журнал изменений в `config/fastleo.php` (`'activity' => ['enabled' => true]`) — на странице заявки появится кнопка «История». Чтобы последние изменения были видны прямо в карточке, включите панель справа.

```php
// app/Fastleo/LeadResource.php
protected static bool $historyPanel = true;
```

В журнал попадают действия в админке. Заявки, созданные кодом сайта (API, телефония), в нём не появляются. Подробнее — [журнал изменений](../activity.md#что-попадает-в-журнал), [панель истории](../related.md#боковая-колонка-карточки).

## Уведомления, отчёты, выгрузка

### Как уведомить менеджера в колокольчик, когда ему назначили заявку?

Повесьте на модель заявки observer и при смене `manager_id` вызывайте `Fastleo::notify()`.

```bash
php artisan make:observer LeadObserver --model=Lead   # app/Observers/LeadObserver.php
```

```php
// app/Observers/LeadObserver.php
namespace App\Observers;

use App\Models\Lead;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;

final class LeadObserver
{
    public function updated(Lead $lead): void
    {
        if (! $lead->wasChanged('manager_id') || $lead->manager === null || $lead->manager_id === Fastleo::guard()->id()) {
            return;
        }

        Fastleo::notify($lead->manager, FastleoMessage::make('Вам назначена заявка')
            ->body((string) $lead->phone)
            ->url(route('fastleo.resources.show', ['leads', $lead->id], false))
            ->icon('user-check'));
    }
}

// app/Models/Lead.php
use App\Observers\LeadObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([LeadObserver::class])]
class Lead extends Model { /* … */ }
```

Observer срабатывает при назначении из формы, из ячейки списка и через импорт. Себе самому уведомление не уходит. Подробнее — [уведомления](../notifications.md#отправить-уведомление).

### Как отправить менеджеру личное сообщение в Telegram?

Боты раздела «Уведомления» пишут в общие чаты администраторов. Чтобы написать конкретному менеджеру, храните его `telegram_chat_id` и отправляйте сообщение своей задачей очереди.

```bash
php artisan make:migration add_telegram_chat_id_to_users_table
php artisan fastleo:make-settings Telegram      # app/Fastleo/TelegramSettings.php
php artisan make:job SendTelegramMessage        # app/Jobs/SendTelegramMessage.php
```

```php
// database/migrations/…_add_telegram_chat_id_to_users_table.php
$table->string('telegram_chat_id', 32)->nullable();

// app/Fastleo/TelegramSettings.php — токен бота задаётся в админке
namespace App\Fastleo;

use Fastleo\Fields\Text;
use Fastleo\Settings;

final class TelegramSettings extends Settings
{
    protected static ?string $label = 'Telegram';

    public function fields(): array
    {
        return [Text::make('token', 'Токен бота')];
    }

    public function encrypted(): array
    {
        return ['token']; // хранится зашифрованным и не выводится в форму
    }
}

// app/Jobs/SendTelegramMessage.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $chatId, public string $text) {}

    public function handle(): void
    {
        $token = (string) fastleo_setting('telegram.token');

        try {
            Http::timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $this->chatId, 'text' => $this->text])
                ->throw();
        } catch (ConnectionException) {
            // Текст этой ошибки содержит адрес запроса с токеном — в журнал он попасть не должен.
            throw new RuntimeException('Telegram недоступен');
        }
    }
}

// в LeadObserver::updated() после Fastleo::notify()
if ($lead->manager->telegram_chat_id) {
    SendTelegramMessage::dispatch($lead->manager->telegram_chat_id, "Вам назначена заявка: {$lead->phone}");
}
```

Менеджер должен один раз написать боту, иначе Telegram не даст отправить ему сообщение. Токен стоит в адресе запроса, поэтому ошибку соединения задача заменяет своей: иначе адрес с токеном попал бы в лог, `failed_jobs` и «Журнал ошибок». Ошибка ответа Telegram (`->throw()`) адреса не содержит — только код и тело ответа. Подробнее — [настройки сайта](../settings.md), [Telegram-боты](integrations.md).

### Как сообщить о новой заявке в рабочий чат Telegram?

Вызовите `Fastleo::notifyAdmins()`, включите раздел ботов (`notifications.bots`, [как](../notifications.md#как-включить-ботов)) и подключите бота в разделе **Система → Уведомления**. Сообщение уйдёт в колокольчик всем администраторам и в чаты бота.

```php
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;

Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body("{$lead->name}, {$lead->phone}")
    ->url(route('fastleo.resources.show', ['leads', $lead->id], false))->level('success'));
```

Шаги:

1. Создайте бота у @BotFather и скопируйте токен.
2. Добавьте бота в рабочую группу и напишите в ней любое сообщение.
3. В админке: **Уведомления → Создать**, вставьте токен, нажмите «Найти чаты бота», выберите группу и сохраните.
4. Нажмите действие «Отправить тест».

Подробнее — [боты Telegram и Max](../notifications.md#боты-telegram-и-max).

### Как сделать отчёт по менеджерам за период?

Создайте страницу-отчёт: фильтр периода, плитки и таблица `Rows` с итогом. Всё считается одним запросом с группировкой.

```bash
php artisan fastleo:make-page ManagersReport --report   # app/Fastleo/ManagersReportPage.php
```

```php
// app/Fastleo/ManagersReportPage.php
namespace App\Fastleo;

use App\Models\Lead;
use App\Models\User;
use Fastleo\Page;
use Fastleo\Reports\Filters;
use Fastleo\Reports\Period;
use Fastleo\Widgets\Rows;
use Fastleo\Widgets\Stat;

final class ManagersReportPage extends Page
{
    protected static ?string $label = 'Отчёт по менеджерам';

    protected static string $icon = 'chart-column';

    protected static ?string $group = 'Отчёты';

    protected static int $columns = 6;

    public function filters(): array
    {
        return [Period::make('period', 'Период')->default('month')];
    }

    public function widgets(Filters $filters): array
    {
        $leads = fn () => $filters->period('period')->apply(Lead::query(), 'created_at');

        return [
            Stat::make('Заявок')->value(fn () => $leads()->count()),
            Stat::make('Продано')->value(fn () => $leads()->where('status', 'won')->count()),
            Stat::make('Выручка, ₽')->value(fn () => (float) $leads()->where('status', 'won')->sum('price')),
            Rows::make('По менеджерам')->columnSpan(6)
                ->columns(['Менеджер', 'Заявок', 'Продано', 'Выручка, ₽'])
                ->rows(function () use ($leads) {
                    $names = User::query()->where('fastleo_admin', true)->pluck('name', 'id');

                    return $leads()
                        ->selectRaw("manager_id, count(*) as total, sum(case when status = 'won' then 1 else 0 end) as won, sum(case when status = 'won' then price else 0 end) as revenue")
                        ->groupBy('manager_id')->get()
                        ->map(fn ($row) => [$names[$row->manager_id] ?? 'Не назначен', (int) $row->total, (int) $row->won, (float) $row->revenue])
                        ->all();
                })
                ->footer(fn () => ['Итого', $leads()->count(), $leads()->where('status', 'won')->count(), (float) $leads()->where('status', 'won')->sum('price')]),
        ];
    }
}
```

Страница откроется по адресу `/fastleo/pages/managers-report`. Чтобы менеджер видел только свою строку, ограничьте запрос в виджете ([кому что показывать](../reports.md#кому-что-показывать)). Подробнее — [страница-отчёт](../reports.md), [другие виджеты](admin-ui.md).

### Как выгрузить заявки в Excel с именем клиента и менеджера?

`BelongsTo` выгружается ключом. Поэтому имена добавьте подзапросами в `query()` ресурса и выведите полями `fromQuery()` — они попадут в xlsx и csv.

```php
// app/Fastleo/LeadResource.php
use App\Models\Client;
use App\Models\User;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query->addSelect([
        'client_name' => Client::select('name')->whereColumn('clients.id', 'leads.client_id')->limit(1),
        'manager_name' => User::select('name')->whereColumn('users.id', 'leads.manager_id')->limit(1),
    ]);
}

// в fields()
Text::make('client_name', 'Клиент')->fromQuery(),
Text::make('manager_name', 'Менеджер (имя)')->fromQuery(),
```

Кнопка «Экспорт» выгружает список с текущими фильтрами и поиском. Подробнее — [рецепт](../recipes.md#выгрузка-с-данными-из-связанных-таблиц), [экспорт](../import-export.md#экспорт), [вычисляемые столбцы](../list.md#вычисляемые-столбцы).
