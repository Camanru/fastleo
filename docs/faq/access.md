# FAQ: Администраторы, права и вход

Кого пускать в админку, кто что может в разделе, вход, пароли, 2FA, журнал изменений и защита боевого сайта. Все вопросы — [список](README.md).

## Доступ в админку

### Как дать человеку доступ в админку?

Выполните команду с его email. Если пользователь уже есть на сайте, он получит доступ; если нет — команда спросит имя и пароль и создаст его.

```bash
php artisan fastleo:user manager@site.ru
```

Без вопросов (скрипт развёртывания):

```bash
php artisan fastleo:user manager@site.ru --name="Анна" --password="длинный-пароль" --no-interaction
```

Новый администратор создаётся и в админке: «Система» → «Администраторы» → «Создать», галочка «Доступ в админку» уже стоит. Пользователя, который уже зарегистрирован на сайте, там не создать (email занят) — ему доступ выдаёт только команда.

Подробнее — [установка, шаг 4](../installation.md#4-администратор), [раздел «Администраторы»](../auth.md#администраторы).

### Как снять доступ с администратора?

Откройте «Система» → «Администраторы», запись человека, снимите галочку «Доступ в админку» и сохраните. На следующем же запросе его выкинет из админки.

Не удаляйте запись в этом разделе, если человек — ещё и пользователь сайта: удаление убирает пользователя из таблицы `users` целиком.

Из консоли:

```bash
php artisan tinker --execute="App\Models\User::where('email', 'manager@site.ru')->first()?->forceFill(['fastleo_admin' => false])->save();"
```

В разделе «Администраторы» себе и последнему администратору доступ не снять — это защита от потери входа. Команда tinker эту защиту обходит; если доступ снят у всех, верните его командой `php artisan fastleo:user owner@site.ru`.

Подробнее — [Администраторы](../auth.md#администраторы).

### Как пускать в админку по своей роли, а не по галочке?

Переопределите `canAccessFastleo()` в модели пользователя:

```php
// app/Models/User.php
public function canAccessFastleo(): bool
{
    return in_array($this->role, ['admin', 'editor', 'manager'], true);
}
```

Затем:

1. Отключите встроенный раздел «Администраторы» — он работает по колонке `fastleo_admin`:

   ```php
   // config/fastleo.php
   'users' => ['resource' => null],
   ```

2. Задайте, кому приходят уведомления админки:

   ```php
   // AppServiceProvider::boot()
   Fastleo::resolveAdminsUsing(fn () => User::query()->whereIn('role', ['admin', 'manager'])->get());
   ```

3. Не добавляйте `role` в `$fillable` модели, если его может отправить форма регистрации или профиля сайта.

Подробнее — [модель пользователя](../installation.md#2-модель-пользователя), [кто получает уведомления](../notifications.md#кто-получает-уведомления).

### Как хранить администраторов в отдельной таблице?

Опишите провайдер в `config/auth.php` и укажите его в `config/fastleo.php`. Модели администраторов нужны интерфейс и трейт.

```php
// config/auth.php
'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'admins' => ['driver' => 'eloquent', 'model' => App\Models\Admin::class],
],

// config/fastleo.php
'auth' => [
    'provider' => 'admins',
],
```

```bash
php artisan make:model Admin -m                   # модель и миграция таблицы admins
```

```php
// app/Models/Admin.php
namespace App\Models;

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable implements FastleoUser
{
    use HasFastleoAccess;

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
```

У таблицы нужны колонки `name`, `email`, `password`, `remember_token`. Затем:

```bash
php artisan migrate                       # колонка fastleo_admin появится в таблице admins
php artisan fastleo:user admin@site.ru
```

Подробнее — [настройки входа](../configuration.md#вход), [переезд: администраторы](../migrating.md#1-администраторы).

### Как закрыть раздел «Администраторы» от всех, кроме владельца?

Сделайте наследника встроенного раздела и разрешите действия только владельцу. `parent::can()` оставляет защиту себя и последнего администратора.

```php
// app/Fastleo/AdminResource.php
namespace App\Fastleo;

use Fastleo\Fastleo;
use Fastleo\Resources\UserResource;
use Illuminate\Database\Eloquent\Model;

final class AdminResource extends UserResource
{
    public function can(string $ability, ?Model $record): bool
    {
        return Fastleo::guard()->user()?->is_owner === true && parent::can($ability, $record);
    }
}
```

```php
// config/fastleo.php
'users' => ['resource' => App\Fastleo\AdminResource::class],
```

Колонку `is_owner` заведите сами: булева, с кастом `'is_owner' => 'boolean'`, не в `$fillable`. Остальные администраторы не увидят раздел в меню, а его адрес ответит 403. Класс обязательно укажите в `users.resource`: иначе встроенный и ваш раздел займут один адрес `users`, и админка сообщит об ошибке.

Подробнее — [Администраторы](../auth.md#администраторы), [ограничение `can()`](../authorization.md#ограничение-без-policy-can).

## Права на разделы и действия

### Как запретить роли открывать раздел?

Создайте Policy модели и верните `false` в `viewAny` для этой роли. Раздел пропадёт из меню, его адрес ответит 403.

```bash
php artisan fastleo:make-policy Order
```

```php
// app/Policies/OrderPolicy.php
public function viewAny(User $user): bool
{
    return in_array($user->role, ['admin', 'manager'], true);
}
```

Команда создаёт все методы, которые спрашивает админка, со значением `true` — правьте нужные. Метод, которого в Policy нет, запрещён.

Подробнее — [права доступа](../authorization.md#с-policy).

### Как дать роли только просмотр раздела?

Оставьте `viewAny` и `view` открытыми, а изменяющие методы Policy — только для нужной роли:

```php
// app/Policies/OrderPolicy.php
public function viewAny(User $user): bool { return true; }
public function view(User $user, Order $order): bool { return true; }

public function create(User $user): bool { return $user->role === 'admin'; }
public function update(User $user, Order $order): bool { return $user->role === 'admin'; }
public function delete(User $user, Order $order): bool { return $user->role === 'admin'; }
public function restore(User $user, Order $order): bool { return $user->role === 'admin'; }
public function forceDelete(User $user, Order $order): bool { return $user->role === 'admin'; }
public function reorder(User $user, Order $order): bool { return $user->role === 'admin'; }
public function import(User $user): bool { return $user->role === 'admin'; }
```

Раздел только для чтения для всех — одна строка в ресурсе: `protected static bool $readonly = true;`.

Подробнее — [права доступа](../authorization.md#с-policy), [раздел только для просмотра](../authorization.md#раздел-только-для-просмотра).

### Как запретить удаление записей?

С Policy — верните `false` в `delete` и `forceDelete`. Без Policy — переопределите `can()` в ресурсе:

```php
// app/Fastleo/ArticleResource.php
use Illuminate\Database\Eloquent\Model;

public function can(string $ability, ?Model $record): bool
{
    return ! in_array($ability, ['delete', 'forceDelete'], true);
}
```

Запретить удаление только части записей (опубликованных, системных):

```php
public function can(string $ability, ?Model $record): bool
{
    return $ability !== 'delete' || ! $record?->is_system;
}
```

Кнопка «Удалить» пропадёт, запрос в обход кнопки получит 403, массовое удаление пропустит такие записи.

Подробнее — [ограничение `can()`](../authorization.md#ограничение-без-policy-can).

### Как сделать, чтобы менеджер видел только свои записи?

Ограничьте выборку в ресурсе через `query()`, а автора ставьте observer'ом:

```php
// app/Fastleo/OfferResource.php
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    $user = Fastleo::guard()->user();

    return $user?->is_admin ? $query : $query->where('manager_id', $user?->getKey());
}
```

```bash
php artisan make:observer OfferObserver --model=Offer   # app/Observers/OfferObserver.php
```

```php
// app/Observers/OfferObserver.php
final class OfferObserver
{
    public function creating(Offer $offer): void
    {
        $offer->manager_id ??= Fastleo::guard()->id();
    }
}
```

Чужая запись не попадёт в список, поиск, экспорт и журнал, её адрес ответит 404. Если чужие записи видеть можно, а менять нельзя — вместо `query()` проверяйте автора в `update()` Policy.

Подробнее — [рецепт «Только свои записи»](../recipes.md#только-свои-записи), [`query()`](../resources.md#query).

### Как скрыть раздел из меню, но оставить доступ по ссылке?

Переопределите `inMenu()` в ресурсе. Адрес раздела продолжит работать, права проверяются как обычно.

```php
// app/Fastleo/ImportLogResource.php
use Fastleo\Fastleo;

public static function inMenu(): bool
{
    return Fastleo::guard()->user()?->role === 'admin';
}
```

Чтобы закрыть раздел совсем, а не только пункт меню, — `viewAny` в Policy (вопрос выше).

Подробнее — [`inMenu()`](../resources.md#inmenu).

### Как показать поле только суперадмину?

Скройте поле замыканием `hidden()`. Скрытое поле не выводится в форме, списке и просмотре и не записывается, даже если его отправить в запросе.

```php
use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Model;

Text::make('note', 'Заметка')
    ->hidden(fn (?Model $record) => ! Fastleo::guard()->user()?->is_super),
```

Колонку `is_super` у пользователей заведите сами и не добавляйте в `$fillable`.

Подробнее — [общие методы полей](../fields/README.md#общие-методы).

### Как дать экспорт и импорт только части администраторов?

Добавьте в Policy модели методы `export` и `import`:

```php
// app/Policies/OrderPolicy.php
public function export(User $user): bool { return $user->role === 'admin'; }
public function import(User $user): bool { return $user->role === 'admin'; }
```

Без этих методов экспорт и загрузка файла разрешены всем, у кого есть `viewAny`. Каждая строка импорта дополнительно проверяет `create`/`update`.

Выключить экспорт в разделе для всех: `protected static bool $exportable = false;`.

Подробнее — [права экспорта и импорта](../import-export.md#права), [таблица методов Policy](../authorization.md#с-policy).

### У модели уже есть Policy для сайта — как не сломать админку?

Админка спрашивает ту же Policy. Пропустите администратора админки в начале каждого метода:

```php
// app/Policies/OrderPolicy.php
public function update(User $user, Order $order): bool
{
    if ($user->fastleo_admin) {
        return true;
    }

    return $order->customer_id === $user->id; // правило личного кабинета
}
```

Подробнее — [права доступа](../authorization.md#с-policy).

### Как закрыть журнал ошибок и кнопку «Очистить кеш» от части администраторов?

Опишите Gate в `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewFastleoLogs', fn (User $user) => $user->is_owner);      // «Журнал ошибок»
Gate::define('clearFastleoCache', fn (User $user) => $user->is_owner);    // кнопка «Очистить кеш» на дашборде
Gate::define('viewFastleoActivity', fn (User $user) => $user->is_owner);  // общий «Журнал» изменений
```

Подробнее — [журнал ошибок](../pages.md#журнал-ошибок), [очистить кеш](../dashboard.md#очистить-кеш), [журнал изменений](../activity.md#где-смотреть).

## Вход и пароли

### Как сделать двухфакторную защиту обязательной?

```php
// config/fastleo.php
'auth' => [
    'two_factor_required' => true,
],
```

```bash
php artisan config:cache   # на боевом сайте
```

Администратор без 2FA после входа попадёт в профиль и не откроет другие страницы, пока не подключит приложение-аутентификатор.

Подробнее — [обязательная 2FA](../auth.md#обязательная-2fa).

### Что делать, если администратор потерял телефон с 2FA?

Пусть войдёт резервным кодом (их выдают при включении защиты). Если кодов нет — сбросьте защиту на сервере:

```bash
php artisan fastleo:user admin@site.ru --reset-two-factor
```

Команда спросит новый пароль — оставьте пустым, чтобы не менять. Чужую защиту можно сбросить и в админке: «Администраторы» → запись → действие «Сбросить 2FA».

Подробнее — [двухфакторная защита](../auth.md#двухфакторная-защита-2fa).

### Как изменить срок «Запомнить меня» или выйти на всех устройствах?

Срок задаётся в днях:

```php
// config/fastleo.php
'auth' => [
    'remember_days' => 7,
],
```

Выйти на всех устройствах — в профиле (`/fastleo/profile`): кнопка «Выйти на других устройствах» (нужен пароль). Смена пароля тоже завершает остальные сеансы и «Запомнить меня».

Подробнее — [профиль](../auth.md#профиль), [настройки входа](../configuration.md#вход).

### Что делать, если вход заблокирован после неверных паролей?

Снимите блокировку аккаунта командой:

```bash
php artisan fastleo:unlock admin@site.ru
```

Ограничение по IP закончится само в течение минуты. Сбросить сразу все счётчики — `php artisan cache:clear` (очищает весь кеш приложения).

Подробнее — [лимиты входа](../security.md#вход).

### Как сбросить пароль администратору, если письмо не приходит?

Задайте новый пароль командой — на вопрос «Новый пароль (пусто — оставить прежний)» введите его (не короче 8 символов):

```bash
php artisan fastleo:user admin@site.ru
```

Почему письма не уходят — [Ошибки и эксплуатация](troubleshooting.md#что-делать-если-письма-не-уходят).

### Что делать, если «Забыли пароль?» падает с ошибкой?

Проверьте, есть ли таблица ссылок сброса:

```bash
php artisan fastleo:doctor
```

Если doctor пишет «Нет таблицы password_reset_tokens для ссылок сброса пароля…», создайте её миграцией:

```bash
php artisan make:migration create_password_reset_tokens_table
```

```php
// database/migrations/…_create_password_reset_tokens_table.php
Schema::create('password_reset_tokens', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});
```

На старом сайте с таблицей `password_resets`, которой пользуется сброс пароля самого сайта, не переименовывайте её — опишите брокер админки на неё:

```php
// config/auth.php
'passwords' => [
    'fastleo' => ['provider' => 'users', 'table' => 'password_resets', 'expire' => 60, 'throttle' => 60],
],
```

Для писем нужна настроенная почта (`MAIL_*` в `.env`).

Подробнее — [забыли пароль](../auth.md#забыли-пароль).

### Как сменить адрес админки или вынести её на поддомен?

```php
// config/fastleo.php
'path' => 'admin',               // https://site.ru/admin
```

Или отдельный домен:

```php
// config/fastleo.php
'path' => '',
'domain' => 'admin.site.ru',     // https://admin.site.ru/
```

```bash
php artisan optimize   # на боевом сайте: пересобрать кеш конфигурации и маршрутов
```

В своём коде ссылки на админку стройте через `route('fastleo.…')` — они поменяются сами.

Подробнее — [адрес](../configuration.md#адрес).

## Журнал изменений

### Как узнать, кто изменил запись?

Если журнал изменений включён (`'activity' => ['enabled' => true]` в `config/fastleo.php`), откройте запись и нажмите «История» — там автор, время и «было → стало» по каждому полю. Все изменения сразу — пункт меню «Журнал» (`/fastleo/activity`), фильтры по разделу и автору.

Записи хранятся 30 дней. Дольше:

```php
// config/fastleo.php
'activity' => [
    'enabled' => true,
    'keep_days' => 90,
],
```

Массив `activity` заменяет умолчание целиком: без `'enabled' => true` журнал выключится.

Чистка старых записей идёт по расписанию — нужен работающий `schedule:run` в cron.

Подробнее — [журнал изменений](../activity.md#где-смотреть).

### Как откатить изменение или вернуть удалённую запись?

Найдите событие в «Журнале» или в «Истории» записи и нажмите «Вернуть» (у удалённой записи — «Восстановить»). Для отката нужно право `update`, для возврата из корзины — `restore`, для пересоздания удалённой навсегда — `create`.

Подробнее — [откат](../activity.md#откат).

### Как не вести журнал для раздела?

```php
// app/Fastleo/AuditLogResource.php
protected static bool $activity = false;
```

Журнал выключен по умолчанию; если вы его включили, выключить целиком — `'enabled' => false` в массиве `activity` в `config/fastleo.php`.

Подробнее — [отключить журнал для раздела](../activity.md#отключить-журнал-для-раздела).

## Безопасность боевого сайта

### Что настроить на сервере перед запуском админки?

1. В `.env`:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   SESSION_SECURE_COOKIE=true
   SESSION_SAME_SITE=lax
   ```

2. Запретите PHP в папке загрузок и ограничьте размер запроса в nginx:

   ```nginx
   location ^~ /storage/ {
       location ~ \.php$ { return 403; }
   }

   client_max_body_size 32m;
   ```

3. За балансировщиком или Cloudflare настройте доверенные прокси (вопрос ниже).
4. На нескольких серверах — общий кеш (`CACHE_STORE=redis` или `database`): в нём лежат лимиты входа.
5. Включите обязательную 2FA (`auth.two_factor_required`).
6. Не меняйте `APP_KEY` без нужды: им зашифрованы секреты 2FA и настроек.
7. Добавьте `composer audit` в CI сайта и выполните `php artisan optimize`.

Подробнее — [что сделать на сайте](../security.md#что-сделать-на-сайте).

### Сайт за Cloudflare или балансировщиком: что сделать для лимитов входа?

Укажите доверенные прокси в `bootstrap/app.php` — иначе все запросы придут «с одного IP», и лимит входа сработает для всех сразу:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12']); // адреса вашего балансировщика или диапазоны Cloudflare
})
```

`at: '*'` ставьте, только если сервер принимает соединения исключительно от прокси.

Подробнее — [что сделать на сайте](../security.md#что-сделать-на-сайте), [Trusted Proxies в Laravel](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies).

### Как пускать в админку только с адресов офиса?

Добавьте свой middleware в `fastleo.middleware` — он проверяет все страницы админки, включая вход:

```bash
php artisan make:middleware AllowOfficeOnly      # app/Http/Middleware/AllowOfficeOnly.php
```

```php
// app/Http/Middleware/AllowOfficeOnly.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AllowOfficeOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array($request->ip(), ['203.0.113.10'], true), 404);

        return $next($request);
    }
}
```

```php
// config/fastleo.php
'middleware' => ['web', App\Http\Middleware\AllowOfficeOnly::class],
```

Правило nginx `location /fastleo { allow …; deny all; }` не используйте: оно обходится адресом `/index.php/fastleo/login`. За прокси сначала настройте доверенные прокси, иначе `ip()` вернёт адрес прокси.

Подробнее — [что сделать на сайте](../security.md#что-сделать-на-сайте).

## Тесты

### Как проверить тестом, что права раздела работают?

Войдите в тесте нужным пользователем через guard `fastleo` и проверьте запрет и то, что запись не изменилась:

```bash
php artisan make:test Admin/DoctorsAccessTest --pest   # tests/Feature/Admin/DoctorsAccessTest.php
```

```php
// tests/Feature/Admin/DoctorsAccessTest.php
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids editors to delete doctors', function (): void {
    $editor = User::factory()->create(['role' => 'editor']);
    $editor->forceFill(['fastleo_admin' => true])->save();
    $doctor = Doctor::factory()->create();

    $this->actingAs($editor, 'fastleo')
        ->delete(route('fastleo.resources.destroy', ['doctors', $doctor->id]))
        ->assertForbidden();

    $this->assertModelExists($doctor);
});

it('does not let a site user into the admin panel', function (): void {
    $this->actingAs(User::factory()->create(), 'fastleo')
        ->get(route('fastleo.resources.index', 'doctors'))
        ->assertRedirect(route('fastleo.login'));
});
```

`actingAs($user)` без `'fastleo'` входит на сайт, а не в админку.

Подробнее — [тесты: права](../testing.md#права), [администратор в тесте](../testing.md#администратор-в-тесте).
