<?php

declare(strict_types=1);

namespace Fastleo;

use Closure;
use Fastleo\Auth\UserLookup;
use Fastleo\Health\HealthChecks;
use Fastleo\Health\HealthResult;
use Fastleo\Http\Middleware\Authenticate;
use Fastleo\Http\Middleware\AuthenticateSession;
use Fastleo\Http\Middleware\EnsureCanAccessFastleo;
use Fastleo\Http\Middleware\RecordActivity;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use LogicException;

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
     * Fastleo::resources()->find('doctors'); // DoctorResource или null
     * ```
     */
    public static function resources(): ResourceRegistry
    {
        return resolve(ResourceRegistry::class);
    }

    /**
     * Middleware страниц админки после входа: проверка входа, сеанса, доступа, обязательной 2FA, запись журнала.
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
            RecordActivity::class,
        ];
    }

    /**
     * Свои маршруты сайта внутри админки: с префиксом, доменом и защитой админки (вход, CSRF, русский язык, заголовки).
     * Адреса — `/fastleo/pages/…`, имена — `fastleo.pages.…`. Берите маршруты длиннее одного сегмента
     * (`report/run`), чтобы не пересечься со страницей `/fastleo/pages/{страница}`.
     *
     * ```php
     * // routes/web.php
     * Fastleo::routes(function () {
     *     Route::post('report/run', RunReportController::class)->name('report-run'); // fastleo.pages.report-run
     * });
     * ```
     *
     * @param  Closure(): void  $routes
     */
    public static function routes(Closure $routes): void
    {
        $domain = config('fastleo.domain');

        Route::group([
            'prefix' => trim(config()->string('fastleo.path', 'fastleo'), '/').'/pages',
            'domain' => is_string($domain) && $domain !== '' ? $domain : null,
            'middleware' => [...config()->array('fastleo.middleware', ['web']), SetLocale::class, SecurityHeaders::class, ...self::adminMiddleware()],
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
        $path = route($route, $parameters, false);
        $domain = config('fastleo.domain');

        if (is_string($domain) && $domain !== '') {
            $scheme = parse_url(config()->string('app.url', ''), PHP_URL_SCHEME);

            return (is_string($scheme) ? $scheme : 'https').'://'.$domain.$path;
        }

        return SeoTags::absolute($path);
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
     * Уведомить всех администраторов: значок в колокольчике шапки и сообщение в чаты включённых ботов
     * раздела «Уведомления» (Telegram, Max).
     * Строка — заголовок уведомления.
     *
     * ```php
     * Fastleo::notifyAdmins('Новая заявка');
     * Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body('Иван')->url(route('fastleo.resources.show', ['leads', 7]))->icon('inbox'));
     * ```
     */
    public static function notifyAdmins(FastleoMessage|string $message): void
    {
        $message = is_string($message) ? FastleoMessage::make($message) : $message;

        $admins = self::admins();
        Notification::send($admins, new MessageNotification($message));

        if (config()->boolean('fastleo.notifications.mail', false)) {
            self::mailAdmins($admins, $message);
        }

        if (config('fastleo.notifications.bots') === null) {
            return;
        }

        $text = $message->toText();

        foreach (NotificationBot::query()->where('enabled', true)->get() as $bot) {
            foreach ($bot->recipients as $chatId) {
                dispatch(new SendAdminMessage($bot, $chatId, $text));
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
     * Уведомить одного администратора (только колокольчик).
     */
    public static function notify(Authenticatable $user, FastleoMessage|string $message): void
    {
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

        return $dashboard instanceof Dashboard ? $dashboard : new Dashboard;
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
