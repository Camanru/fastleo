<?php

declare(strict_types=1);

namespace Fastleo;

use Closure;
use Fastleo\Auth\RoleMatrix;
use Fastleo\Auth\UserLookup;
use Fastleo\Health\HealthChecks;
use Fastleo\Health\HealthResult;
use Fastleo\Http\Middleware\Authenticate;
use Fastleo\Http\Middleware\AuthenticateSession;
use Fastleo\Http\Middleware\AuthorizePage;
use Fastleo\Http\Middleware\EnsureCanAccessFastleo;
use Fastleo\Http\Middleware\RequireTwoFactor;
use Fastleo\Http\Middleware\SecurityHeaders;
use Fastleo\Http\Middleware\SetLocale;
use Fastleo\Notifications\FastleoMessage;
use Fastleo\Notifications\MessageMailNotification;
use Fastleo\Notifications\MessageNotification;
use Fastleo\Notifications\NotificationBot;
use Fastleo\Notifications\SendAdminMessage;
use Fastleo\Support\ResourceRegistry;
use Fastleo\Support\SeoTags;
use Fastleo\Support\SettingsStore;
use Fastleo\Support\UserModel;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Throwable;
use WeakMap;

/**
 * Общие точки входа пакета.
 */
final class Fastleo
{
    /**
     * Имя guard, через который входят в админку. Регистрируется провайдером пакета
     * поверх провайдера пользователей из config('fastleo.auth.provider').
     */
    public const string GUARD = 'fastleo';

    /**
     * Язык интерфейса админки: тексты пакета есть только на русском.
     */
    public const string LOCALE = 'ru';

    /**
     * Guard админки.
     *
     * ```php
     * Fastleo::guard()->user(); // вошедший администратор или null
     * ```
     */
    public static function guard(): SessionGuard
    {
        $guard = resolve(AuthFactory::class)->guard(self::GUARD);

        if (! $guard instanceof SessionGuard) {
            throw new LogicException(__('fastleo::doctor.guard_driver', ['driver' => $guard::class], self::LOCALE).' '.__('fastleo::doctor.guard_driver_hint', [], self::LOCALE));
        }

        return $guard;
    }

    /**
     * Реестр ресурсов сайта.
     *
     * ```php
     * Fastleo::resources()->find('products'); // ProductResource или null
     * ```
     */
    public static function resources(): ResourceRegistry
    {
        return resolve(ResourceRegistry::class);
    }

    /**
     * Middleware страниц админки после входа: проверка входа, сеанса, доступа, обязательной 2FA.
     *
     * @return list<string>
     */
    public static function adminMiddleware(): array
    {
        return [
            Authenticate::using(self::GUARD),
            AuthenticateSession::class,
            EnsureCanAccessFastleo::class,
            RequireTwoFactor::class,
        ];
    }

    /**
     * Свои маршруты сайта внутри админки: с префиксом, доменом и защитой админки (вход, CSRF, русский язык, заголовки).
     * Адреса — `/fastleo/pages/…`, имена — `fastleo.pages.…`. Берите маршруты длиннее одного сегмента
     * (`report/run`), чтобы не пересечься со страницей `/fastleo/pages/{страница}`.
     *
     * Маршруты кнопок и форм своей страницы привязывайте к ней (`page:`): тогда они проверяют то же, что и страница, —
     * `isAvailable()` (иначе 404) и `canAccess()` администратора (иначе 403). Без `page` маршрут открыт любому
     * администратору.
     *
     * ```php
     * // routes/web.php
     * Fastleo::routes(function () {
     *     Route::post('report/run', RunReportController::class)->name('report-run'); // fastleo.pages.report-run
     * }, page: ReportPage::class);
     * ```
     *
     * @param  Closure(): void  $routes
     * @param  class-string<Page>|null  $page  страница, права которой действуют на маршрутах
     */
    public static function routes(Closure $routes, ?string $page = null): void
    {
        $domain = config('fastleo.domain');

        Route::group([
            'prefix' => trim(config()->string('fastleo.path', 'fastleo'), '/').'/pages',
            'domain' => is_string($domain) && $domain !== '' ? $domain : null,
            'middleware' => [
                ...config()->array('fastleo.middleware', ['web']),
                SetLocale::class,
                SecurityHeaders::class,
                ...self::adminMiddleware(),
                ...($page === null ? [] : [AuthorizePage::class.':'.$page]),
            ],
            'as' => 'fastleo.pages.',
        ], $routes);
    }

    /**
     * Как найти администраторов для `notifyAdmins()` (по умолчанию — пользователи с `fastleo_admin`, которым
     * `canAccessFastleo()` разрешает вход).
     *
     * @var (Closure(): iterable<int, Authenticatable>)|null
     */
    private static ?Closure $adminsResolver = null;

    /**
     * Кому показывать колокольчик (по умолчанию — всем администраторам, пока он включён в конфиге).
     *
     * @var (Closure(Authenticatable): bool)|null
     */
    private static ?Closure $bellResolver = null;

    /**
     * Как узнать, что пользователь — владелец (`resolveOwnerUsing()`); `null` — не задано.
     *
     * @var (Closure(Authenticatable): bool)|null
     */
    private static ?Closure $ownerResolver = null;

    /**
     * Ответы резолвера владельца на время запроса: по объекту пользователя, а не по его ключу, чтобы долгий процесс
     * (очередь, MCP) не помнил старый ответ после смены прав.
     *
     * @var WeakMap<Authenticatable, bool>|null
     */
    private static ?WeakMap $ownerCache = null;

    /**
     * Абсолютный адрес страницы админки для писем: от `APP_URL` (или от `fastleo.domain`), а не от заголовка Host
     * запроса, — иначе подменённый Host попал бы в ссылку сброса пароля.
     *
     * ```php
     * Fastleo::mailUrl('fastleo.profile.show');
     * ```
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function mailUrl(string $route, array $parameters = []): string
    {
        return self::adminUrl(route($route, $parameters, false));
    }

    /**
     * Полный адрес по пути внутри админки (`/fastleo/leads/5`): если задан `fastleo.domain` — на нём (схема от `APP_URL`),
     * иначе от `APP_URL`. Заголовок Host запроса не используется: посетитель сайта не может подменить домен ссылки,
     * а письмо из очереди и вебхук получают тот же адрес. Полный адрес (`https://…`) возвращается как есть.
     *
     * ```php
     * Fastleo::adminUrl('/fastleo/leads/5');   // https://admin.site.ru/fastleo/leads/5
     * ```
     */
    public static function adminUrl(string $path): string
    {
        $domain = config('fastleo.domain');

        if (preg_match('#^https?://#i', $path) === 1 || ! is_string($domain) || $domain === '') {
            return SeoTags::absolute($path);
        }

        $scheme = parse_url(config()->string('app.url', ''), PHP_URL_SCHEME);

        return (is_string($scheme) ? $scheme : 'https').'://'.$domain.'/'.ltrim($path, '/');
    }

    /**
     * Свой способ выбрать администраторов для уведомлений (например, по роли). Вызывается в `AppServiceProvider::boot()`.
     *
     * ```php
     * Fastleo::resolveAdminsUsing(fn () => User::role('editor')->get());
     * ```
     *
     * @param  (Closure(): iterable<int, Authenticatable>)|null  $resolver  null возвращает выбор по умолчанию
     */
    public static function resolveAdminsUsing(?Closure $resolver): void
    {
        self::$adminsResolver = $resolver;
    }

    /**
     * Администраторы, которым идут уведомления.
     *
     * @return list<Authenticatable>
     */
    public static function admins(): array
    {
        if (self::$adminsResolver instanceof Closure) {
            return [...(self::$adminsResolver)()];
        }

        $model = UserModel::fastleoClassName();

        if ($model === null) {
            return [];
        }

        $admins = [];

        foreach ($model::query()->where('fastleo_admin', true)->get() as $user) {
            if ($user instanceof Authenticatable && $user->canAccessFastleo()) {
                $admins[] = $user;
            }
        }

        return $admins;
    }

    /**
     * Сказать панели, кто здесь владелец, без ролей `spatie/laravel-permission`: остальным закрываются файлы, журнал ошибок,
     * очистка кеша, SoftNetica, настройки, свои страницы, раздел пользователей и разделы с `$ownerOnly`. Вызывается в
     * `AppServiceProvider::boot()`. При включённых ролях владельца определяет роль, резолвер не действует.
     *
     * Ответ считается только по `=== true`; исключение внутри замыкания — в лог и «не владелец».
     *
     * ```php
     * Fastleo::resolveOwnerUsing(fn (User $user) => $user->is_owner);
     * ```
     *
     * @param  (Closure(Authenticatable): bool)|null  $resolver  null возвращает прежнее поведение (владельца нет)
     */
    public static function resolveOwnerUsing(?Closure $resolver): void
    {
        self::$ownerResolver = $resolver;
        self::$ownerCache = null;
    }

    /**
     * Свой способ определить владельца задан (`resolveOwnerUsing()`).
     *
     * ```php
     * Fastleo::hasOwnerResolver(); // true после resolveOwnerUsing(fn ($user) => …)
     * ```
     */
    public static function hasOwnerResolver(): bool
    {
        return self::$ownerResolver instanceof Closure;
    }

    /**
     * Пользователь — владелец: роль-владелец при включённых ролях, иначе ответ `resolveOwnerUsing()`; нет ни того ни другого —
     * нет. Без аргумента — вошедший в панель; без пользователя (консоль, очередь без входа) — нет. Сайту: проверка в Policy
     * и в коде вместо своего `$user->is_owner`.
     *
     * ```php
     * if (Fastleo::isOwner()) { … }
     * Fastleo::isOwner($manager);
     * ```
     */
    public static function isOwner(?Authenticatable $user = null): bool
    {
        $user ??= self::guard()->user();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        if (RoleMatrix::enabled()) {
            return RoleMatrix::hasOwnerRole($user);
        }

        if (! self::$ownerResolver instanceof Closure) {
            return false;
        }

        $cache = self::$ownerCache ??= new WeakMap();

        if (! $cache->offsetExists($user)) {
            try {
                $cache->offsetSet($user, (self::$ownerResolver)($user) === true);
            } catch (Throwable $e) {
                report($e);
                $cache->offsetSet($user, false);
            }
        }

        return $cache->offsetGet($user);
    }

    /**
     * Показывать колокольчик (и раздел «Уведомления») только некоторым администраторам, например владельцу: у остальных нет
     * колокольчика, их адреса отвечают 404, а уведомления им не сохраняются. Вызывается в `AppServiceProvider::boot()`;
     * включённый в конфиге колокольчик остаётся главным выключателем.
     *
     * ```php
     * Fastleo::showBellTo(fn (User $user) => $user->is_owner);
     * ```
     *
     * @param  (Closure(Authenticatable): bool)|null  $resolver  null возвращает показ всем администраторам
     */
    public static function showBellTo(?Closure $resolver): void
    {
        self::$bellResolver = $resolver;
    }

    /**
     * Колокольчик включён в конфиге и показывается этому администратору (см. `showBellTo()`). Адресат, который не
     * Authenticatable, при заданном выборе колокольчика не получает.
     */
    public static function bellFor(?object $user): bool
    {
        if (! self::bell()) {
            return false;
        }

        return ! self::$bellResolver instanceof Closure || ($user instanceof Authenticatable && (self::$bellResolver)($user) === true);
    }

    /**
     * Колокольчик в шапке включён (`fastleo.notifications.bell`, по умолчанию выключен). Выключен — колокольчика нет,
     * его адреса отвечают 404, а канал `fastleo` ничего не сохраняет. Значение читается мягко (`1`, `'true'` — включён,
     * `null` из `env()` без переменной — выключен): проверка идёт на каждой странице и в `notifyAdmins()` с форм сайта.
     */
    public static function bell(): bool
    {
        return filter_var(config('fastleo.notifications.bell', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Как часто колокольчик обновляет число непрочитанных, в миллисекундах (`fastleo.notifications.poll` в секундах,
     * не чаще раза в 10 секунд и не реже раза в сутки); 0 — не опрашивать (`null` или 0 в конфиге).
     */
    public static function bellPoll(): int
    {
        $seconds = config('fastleo.notifications.poll', 60);

        // Не реже раза в сутки: таймер браузера считает задержку в int32 мс, и больше ~25 дней опрос пошёл бы без паузы.
        return is_numeric($seconds) && (float) $seconds >= 1 ? (int) min(max(10, (float) $seconds), 86400) * 1000 : 0;
    }

    /**
     * Копия уведомлений `notifyAdmins()` на почту включена (`fastleo.notifications.mail`, по умолчанию выключена).
     * Значение читается мягко, как в `bell()`: `null` из `env()` без переменной не роняет форму сайта.
     */
    public static function mail(): bool
    {
        return filter_var(config('fastleo.notifications.mail', false), FILTER_VALIDATE_BOOL);
    }

    /** Каналы `notifyAdmins()`: колокольчик, почта, боты раздела «Боты уведомлений». */
    public const array CHANNELS = ['bell', 'mail', 'bots'];

    /**
     * Уведомить всех администраторов во включённые каналы: колокольчик в шапке (`notifications.bell`), письмо
     * (`notifications.mail`), чаты ботов раздела «Боты уведомлений» (`notifications.bots`, Telegram и Max).
     * Строка — заголовок уведомления. `$via` — только эти каналы из `bell`, `mail`, `bots` (выключенный в конфиге
     * пропускается); null — все включённые. Неизвестное имя канала или пустой список — `InvalidArgumentException`.
     *
     * ```php
     * Fastleo::notifyAdmins('Новая заявка');
     * Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body('Иван')->url(Fastleo::recordUrl($lead, false))->icon('inbox'));
     * Fastleo::notifyAdmins('Сайт недоступен', via: ['bots']);
     * ```
     *
     * @param  list<mixed>|null  $via  имена из `CHANNELS`; остальное (опечатка, не строка) — исключение
     */
    public static function notifyAdmins(FastleoMessage|string $message, ?array $via = null): void
    {
        if ($via === []) {
            throw new InvalidArgumentException(sprintf('Fastleo::notifyAdmins(): пустой список каналов via, допустимы: %s; во все включённые — без via.', implode(', ', self::CHANNELS)));
        }

        $unknown = array_map(get_debug_type(...), array_filter($via ?? [], fn (mixed $channel): bool => ! is_string($channel)))
            + array_diff(array_filter($via ?? [], is_string(...)), self::CHANNELS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Fastleo::notifyAdmins(): неизвестный канал «%s», допустимы: %s.', implode('», «', $unknown), implode(', ', self::CHANNELS)));
        }

        $via ??= self::CHANNELS;
        $message = is_string($message) ? FastleoMessage::make($message) : $message;
        $bell = in_array('bell', $via, true) && self::bell();
        $mail = in_array('mail', $via, true) && self::mail();
        // Список администраторов нужен только колокольчику и почте: форма заявки без них не делает лишний запрос.
        $admins = $bell || $mail ? self::admins() : [];

        if ($bell) {
            Notification::send($admins, new MessageNotification($message));
        }

        if ($mail) {
            self::mailAdmins($admins, $message);
        }

        if (! in_array('bots', $via, true) || config('fastleo.notifications.bots') === null) {
            return;
        }

        $text = $message->toText();

        // Очередь sync на веб-запросе: страницы сайта (форма заявки) не ждут ответа мессенджера — отправка идёт после ответа.
        $afterResponse = ! app()->runningInConsole() && config('queue.default') === 'sync';

        // Боты включены, а миграции для них ещё нет (или база недоступна): форма заявки на сайте не должна отвечать 500.
        foreach (rescue(fn (): array => NotificationBot::query()->where('enabled', true)->get()->all(), []) as $bot) {
            foreach ($bot->recipients as $chatId) {
                $pending = dispatch(new SendAdminMessage($bot, $chatId, $text))->afterCommit();

                if ($afterResponse) {
                    $pending->afterResponse();
                }
            }
        }
    }

    /**
     * Письмо с уведомлением каждому администратору, у которого есть адрес. Сбой отправки одного письма не мешает
     * остальным и не ломает код сайта, который вызвал `notifyAdmins()`.
     *
     * @param  list<Authenticatable>  $admins
     */
    private static function mailAdmins(array $admins, FastleoMessage $message): void
    {
        foreach ($admins as $admin) {
            $email = UserLookup::emailOf($admin);

            if ($email !== '') {
                rescue(fn () => Notification::route('mail', $email)->notify(new MessageMailNotification($message)));
            }
        }
    }

    /**
     * Адрес, по которому администратору открыть запись (для уведомлений, писем, ссылок сайта): страница просмотра, если она
     * включена (`fastleo.record_view`), иначе форма правки; null — у модели нет раздела. Права не проверяются (ссылку строят и там,
     * где администратора нет: вебхук, очередь, команда), открывшаяся страница проверит их сама. Полный адрес строится через
     * `adminUrl()` — от `fastleo.domain` или `APP_URL`, а не от хоста запроса; `$absolute = false` — путь без хоста
     * (`FastleoMessage` достроит его так же). Адрес с проверкой прав для списков и поиска — `Resource::openUrl()`.
     *
     * ```php
     * Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->url(Fastleo::recordUrl($lead, false)));
     * ```
     */
    public static function recordUrl(Model $record, bool $absolute = true): ?string
    {
        $path = self::resources()->forModel($record::class)?->recordAddress($record, false);

        return $path === null || ! $absolute ? $path : self::adminUrl($path);
    }

    /**
     * Уведомить одного администратора (только колокольчик; при выключенном колокольчике ничего не делает).
     */
    public static function notify(Authenticatable $user, FastleoMessage|string $message): void
    {
        if (! self::bell()) {
            return;
        }

        Notification::send([$user], new MessageNotification(is_string($message) ? FastleoMessage::make($message) : $message));
    }

    /**
     * Своя проверка сайта для отчёта в SoftNetica (когда сайт подключён). Вызывается в `AppServiceProvider::boot()`;
     * замыкание выполняется раз в 5 минут и возвращает `HealthResult::ok()`, `warning($текст)` или `failed($текст)`.
     * Исключение внутри проверки превращается в `failed`, отчёт не ломается.
     *
     * ```php
     * Fastleo::healthCheck('leads', 'Заявки', fn () => Lead::where('created_at', '>', now()->subDays(3))->exists()
     *     ? HealthResult::ok()
     *     : HealthResult::warning('Заявок нет уже 3 дня'));
     * ```
     *
     * @param  Closure(): HealthResult  $check
     */
    public static function healthCheck(string $name, string $label, Closure $check): void
    {
        resolve(HealthChecks::class)->register($name, $label, $check);
    }

    /**
     * Значение настройки сайта `страница.поле` (то же, что хелпер `fastleo_setting()`). Пустое значение
     * (`null` или `''`) заменяется запасным `$default`, а если страница ещё ни разу не сохранялась, берётся
     * значение по умолчанию самого поля.
     *
     * ```php
     * Fastleo::setting('contacts.phone');
     * ```
     */
    public static function setting(string $key, mixed $default = null): mixed
    {
        [$slug, $name] = array_pad(explode('.', $key, 2), 2, '');

        if ($slug === '' || $name === '') {
            return $default;
        }

        $page = self::resources()->findSettings($slug);
        $stored = resolve(SettingsStore::class)->values($slug, $page);
        $value = $stored[$name] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        if (array_key_exists($name, $stored)) {
            return $default;
        }

        $field = $page?->schema()->field($name);

        return $field instanceof Fields\Field ? ($field->untouchedValue() ?? $default) : $default;
    }

    /**
     * Дашборд: класс `Dashboard` рядом с ресурсами сайта (`app/Fastleo/Dashboard.php`) или встроенный.
     *
     * ```php
     * Fastleo::dashboard()->widgets(); // список виджетов главной
     * ```
     */
    public static function dashboard(): Dashboard
    {
        $class = trim(config()->string('fastleo.resources.namespace'), '\\').'\\Dashboard';

        $dashboard = class_exists($class) && is_subclass_of($class, Dashboard::class) ? resolve($class) : null;

        return $dashboard instanceof Dashboard ? $dashboard : new Dashboard();
    }

    /**
     * Часовой пояс админки: в нём показываются и вводятся дата и время.
     * `fastleo.timezone`, если это известный пояс, иначе пояс сайта (`app.timezone`).
     *
     * ```php
     * Fastleo::timezone(); // 'Europe/Moscow'
     * ```
     */
    public static function timezone(): string
    {
        $timezone = config('fastleo.timezone');

        if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return self::appTimezone();
    }

    /**
     * Языки содержимого для многоязычных полей (`->translatable()`): `fastleo.locales`,
     * по умолчанию — язык сайта. Первый — основной: его показывает список, по нему сортировка.
     *
     * ```php
     * Fastleo::locales(); // ['ru', 'en']
     * ```
     *
     * @return non-empty-list<string>
     */
    public static function locales(): array
    {
        $configured = config('fastleo.locales');
        $locales = [];

        foreach (is_array($configured) ? $configured : [] as $locale) {
            if (is_string($locale) && preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})?$/', $locale) === 1 && ! in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        if ($locales === []) {
            $app = config('app.locale');
            $locales[] = is_string($app) && $app !== '' ? $app : 'ru';
        }

        return $locales;
    }

    /**
     * Часовой пояс сайта, в котором значения хранятся в базе.
     */
    public static function appTimezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
