# Рецепты: клиенты, заявки, менеджеры

Типовые задачи рабочих систем (CRM, заявки, заказы), которые решаются обычным кодом Laravel рядом с fastleo — новых возможностей в пакете для них не нужно. Примеры короткие: подставьте свои модели.

## Несколько телефонов у клиента

Телефоны — отдельная таблица `client_phones`, а в форме клиента — блоки [`HasMany`](fields/has-many.md). Нормализация — мутатор модели, поэтому она работает и в форме, и при импорте, и из кода сайта.

```php
// ClientResource::fields()
HasMany::make('phones', 'Телефоны')->fields([Tel::make('phone', 'Телефон')->required()])->max(10),

// App\Models\ClientPhone
protected function phone(): Attribute
{
    return Attribute::set(fn (string $value): string => self::normalize($value));
}

/** 8 (900) 123-45-67 и +7 900 123 45 67 → 79001234567 */
public static function normalize(?string $value): string
{
    $digits = preg_replace('/\D/', '', (string) $value) ?? '';

    return strlen($digits) === 11 && $digits[0] === '8' ? '7'.substr($digits, 1) : $digits;
}
```

- **Поиск клиента по номеру.** Поиск списка идёт по колонкам таблицы клиентов, а номера лежат в другой. Держите в `clients` колонку `phones_digits` — все номера через пробел, её обновляет observer `ClientPhone` (`saved`/`deleted`) — и покажите её отмеченной `Text::make('phones_digits', 'Номера')->onlyInList()->searchable()`. Колонка хранит только цифры, поэтому ищется по цифрам, подстрока работает: `9001234567` найдёт `79001234567`.
- **Дубль при вводе номера.** Основной номер уникален, а при дубле форма сама показывает ссылку на существующего клиента — `duplicateOf()` ([fields/README.md](fields/README.md)):

```php
Tel::make('phone', 'Телефон')->required()
    ->rules(fn (?Client $record) => [Rule::unique('clients', 'phone')->ignore($record)])
    ->duplicateOf(fn (string $phone) => Client::query()->where('phone', $phone)->first()),
```

  Под полем появится «Такое значение уже есть. Открыть: Иванов Иван» — ссылка, если у администратора есть право открыть эту запись.
- **Дубль: открыть существующего клиента.** Из входящей заявки кнопка ведёт к клиенту с таким номером, а если его нет — к форме нового клиента:

```php
final class OpenClient extends Action
{
    protected ?string $label = 'Открыть клиента';
    protected string $icon = 'user';

    public function handle(Collection $records, array $data): ActionResult
    {
        $lead = $records->firstOrFail();
        $client = ClientPhone::query()->where('phone', ClientPhone::normalize($lead->phone))->first()?->client;

        return ActionResult::redirect($client
            ? route('fastleo.resources.show', ['clients', $client->id], false)
            : route('fastleo.resources.create', ['resource' => 'clients', 'prefill' => ['name' => (string) $lead->name]], false));
    }
}
```

  Подставить в форму можно простые поля клиента (`prefill[name]=…`, [layout.md](layout.md#форма-создания-заполненная-из-ссылки)); телефоны лежат в блоках `HasMany`, их подставить нельзя — если у клиента один основной номер, держите его колонкой `phone` и подставляйте `prefill[phone]`.

## Только свои записи

Две разные задачи, обе решаются Policy и `query()`:

```php
// Менеджер видит все заявки, но менять и удалять может только свои (и админ — любые)
final class OfferPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Offer $offer): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Offer $offer): bool { return $user->is_admin || $offer->manager_id === $user->id; }
    public function delete(User $user, Offer $offer): bool { return $user->is_admin; }
}
```

Чтобы чужие заявки не видел вовсе, ограничьте раздел: чужая запись не попадёт в список, поиск, экспорт и [связанные таблицы](related.md), а её адрес даст 404.

```php
// OfferResource
public function query(Builder $query): Builder
{
    $user = Fastleo::guard()->user();

    return $user?->is_admin ? $query : $query->where('manager_id', $user?->getKey());
}
```

Автора записи ставит observer, а не поле формы: так менеджер не назначит заявку другому, подставив значение в запрос.

```php
final class OfferObserver
{
    public function creating(Offer $offer): void
    {
        $offer->manager_id ??= Fastleo::guard()->id();
    }
}
```

Фильтр «менеджер — я» по умолчанию: `BelongsToFilter::make('manager_id', 'Менеджер')->default(fn () => auth('fastleo')->id())` ([list.md](list.md#фильтры)). Фильтр берёт подпись и варианты у поля `BelongsTo` с тем же именем, поэтому в ресурсе нужно поле `BelongsTo::make('manager_id', 'Менеджер')->readonly()` — в форме оно не правится (автора ставит observer), но фильтр и колонка списка работают. Это удобство, а не защита: права держат Policy и `query()`.

## «Взять в работу» без гонки

Два менеджера нажимают кнопку на одной заявке в одну секунду. Условное обновление отдаёт её только первому:

```php
final class TakeLead extends Action
{
    protected ?string $label = 'Взять в работу';
    protected string $icon = 'hand';

    public function handle(Collection $records, array $data): ActionResult
    {
        $taken = 0;

        foreach ($records as $lead) {
            // один UPDATE: заявка достанется тому, кто успел
            $taken += Lead::query()->whereKey($lead->getKey())->whereNull('manager_id')->update(['manager_id' => Fastleo::guard()->id()]);
        }

        return $taken === 0
            ? ActionResult::error('Заявку уже взял другой менеджер.')
            : ActionResult::success("Взято: {$taken} из {$records->count()}");
    }
}
```

Сначала невзятые — порядок в `query()`: `$query->orderByRaw('manager_id is not null')->latest()`; список с [автообновлением](list.md#автообновление-списка) (`$poll = 10`) показывает новые заявки сам. Прямое `update()` не вызывает события модели; если на сайте есть observers, берите заявку транзакцией с `lockForUpdate()` и сохраняйте моделью.

## Счётчики в меню и порядок групп

Менеджеру нужно видеть, сколько невзятых входящих и просроченных задач. Число рядом с пунктом меню — `badge()` ресурса ([resources.md](resources.md#badge)), порядок групп — `menu.groups` в `config/fastleo.php` ([configuration.md](configuration.md)):

```php
// LeadResource
public static function badge(): ?int
{
    return Lead::query()->whereNull('manager_id')->count() ?: null;
}

// TaskResource: просроченные задачи текущего менеджера
public static function badge(): ?int
{
    return Task::query()->whereNull('done_at')->where('due_at', '<', now())
        ->where('manager_id', Fastleo::guard()->id())->count() ?: null;
}

// config/fastleo.php
'menu' => ['groups' => ['Работа', 'Заявки', 'Клиенты', 'Справочники']],
```

Метод вызывается на каждой странице админки: условие должно опираться на индекс (`manager_id`, `due_at`).

## Заявки с сайта и из телефонии

Приём — обычный маршрут сайта с токеном; админка только показывает результат и сообщает о новой заявке.

```php
// routes/api.php (файл появляется после `php artisan install:api`)
Route::post('leads', function (Request $request) {
    $token = (string) config('services.leads.token');
    abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

    $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'text' => ['nullable', 'string', 'max:2000']]);
    $lead = Lead::create($data);

    Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body($lead->phone)->url(route('fastleo.resources.show', ['leads', $lead->id], false))->level('success'));

    return response()->json(['id' => $lead->id], 201);
})->middleware('throttle:60,1');
```

`hash_equals` сравнивает токены за постоянное время; токен хранится в `.env` (`config/services.php`), а не в коде. Проверка `$token === ''` обязательна: с пустым `LEADS_TOKEN=` запрос без заголовка иначе прошёл бы. Уведомление приходит в колокольчик и, если включены [боты](notifications.md), в Telegram.

Пропущенные звонки, которые отдаёт телефония по запросу, забирает команда по расписанию:

```php
// routes/console.php
Schedule::command('leads:import-missed-calls')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// команда
$calls = Http::withToken((string) fastleo_setting('telephony.token'))->timeout(10)->retry(2, 500)->get(config('services.telephony.url').'/lost-calls', ['minutes' => 10])->throw()->json();
```

Токен сервиса удобно хранить в [настройках](settings.md) полем `Secret`: администратор меняет его в админке, в исходном коде страницы токена нет. Не записывайте токены в код и не коммитьте. Планировщику нужен cron `* * * * * php artisan schedule:run` ([installation.md](installation.md)).

## Файлы заявки

Папка файлов на каждую заявку — блоки `HasMany` с полем `File` внутри (разрешено, [has-many.md](fields/has-many.md)):

```php
HasMany::make('attachments', 'Файлы')->fields([File::make('path', 'Файл')->directory('offers')->extensions(['pdf', 'jpg', 'png', 'docx']), Text::make('title', 'Название')])->max(20),
```

Файлы лежат на диске `Storage` по пути из колонки; проверки имён и расширений — как у любого поля файла ([files.md](files.md)).

## Выгрузка с данными из связанных таблиц

В выгрузке продаж нужны клиент, телефон и машина. Посчитайте их в `query()` раздела вариантов и покажите [вычисляемыми столбцами](list.md#вычисляемые-столбцы) — они попадают в xlsx/csv:

```php
public function query(Builder $query): Builder
{
    return $query->addSelect([
        'client_name' => Client::select('name')->whereColumn('clients.id', 'variants.client_id')->limit(1),
        'client_phone' => ClientPhone::select('phone')->whereColumn('client_phones.client_id', 'variants.client_id')->limit(1),
    ]);
}

Text::make('client_name', 'Клиент')->fromQuery(),
Tel::make('client_phone', 'Телефон')->fromQuery(),
```

Кодировку и разделитель csv определяет экспорт раздела ([import-export.md](import-export.md)); для русского Excel это UTF-8 с BOM и «;».

## Старые пароли при переезде

Если прежняя система хранила пароли в своём формате (например, `md5(strrev(md5($пароль)))`), входить с ними можно, не сбрасывая: свой `Hasher` проверяет старый хеш, а при первом входе fastleo перезаписывает его обычным bcrypt (`needsRehash()`).

```php
final class LegacyHasher extends BcryptHasher
{
    public function check($value, $hashedValue, array $options = []): bool
    {
        if ($this->isLegacy($hashedValue)) {
            return hash_equals($hashedValue, md5(strrev(md5($value))));
        }

        return parent::check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->isLegacy($hashedValue) || parent::needsRehash($hashedValue, $options);
    }

    private function isLegacy(?string $hash): bool
    {
        return $hash !== null && preg_match('/^[a-f0-9]{32}$/', $hash) === 1;
    }
}

// AppServiceProvider::boot()
Hash::extend('legacy', fn () => new LegacyHasher(config('hashing.bcrypt')));
// .env: HASH_DRIVER=legacy
```

**Старые хеши переносите в базу напрямую** — `DB::table('users')->insert([... 'password' => $oldHash])` или `update()`, а не через модель. Модель пользователя Laravel с кастом `'password' => 'hashed'` не узнаёт md5 как хеш и захеширует его ещё раз: после этого ни старый, ни новый пароль не подойдут.

После того как все администраторы и пользователи вошли хотя бы раз, уберите `LegacyHasher`: старые хеши md5 слабы, и держать их дольше нужного незачем ([migrating.md](migrating.md#1-администраторы)). Тот, кто не вошёл, сбросит пароль письмом.
