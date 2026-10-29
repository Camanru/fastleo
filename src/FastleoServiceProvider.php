<?php

declare(strict_types=1);

namespace Fastleo;

use Fastleo\Activity\ActivityRecorder;
use Fastleo\Auth\LoginHistory;
use Fastleo\Closing\SiteClosing;
use Fastleo\Console\CacheCommand;
use Fastleo\Console\ClearCommand;
use Fastleo\Console\DoctorCommand;
use Fastleo\Console\ImagesCommand;
use Fastleo\Console\InspectCommand;
use Fastleo\Console\InstallCommand;
use Fastleo\Console\MakeActionCommand;
use Fastleo\Console\MakeDashboardCommand;
use Fastleo\Console\MakeFieldCommand;
use Fastleo\Console\MakeFilterCommand;
use Fastleo\Console\MakePageCommand;
use Fastleo\Console\MakePolicyCommand;
use Fastleo\Console\MakeResourceCommand;
use Fastleo\Console\MakeSettingsCommand;
use Fastleo\Console\MakeWidgetCommand;
use Fastleo\Console\McpCommand;
use Fastleo\Console\PruneCommand;
use Fastleo\Console\SkillCommand;
use Fastleo\Console\SoftNeticaSyncCommand;
use Fastleo\Console\UnlockCommand;
use Fastleo\Console\UserCommand;
use Fastleo\Health\HealthChecks;
use Fastleo\Http\Middleware\SecurityHeaders;
use Fastleo\Notifications\FastleoChannel;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\Status;
use Fastleo\SoftNetica\SyncState;
use Fastleo\Support\Assets;
use Fastleo\Support\ResourceRegistry;
use Fastleo\Support\SchedulerPulse;
use Fastleo\Support\SettingsStore;
use Fastleo\Support\Sitemap;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Подключает fastleo к сайту: конфиг, guard, маршруты, шаблоны, переводы, миграции и команды.
 */
final class FastleoServiceProvider extends ServiceProvider
{
    /**
     * Конфиг пакета, реестр ресурсов и guard fastleo.
     *
     * Guard создаётся поверх провайдера пользователей из `fastleo.auth.provider`;
     * сайт может описать guard fastleo в config/auth.php сам — тогда он не трогается.
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('config/fastleo.php'), 'fastleo');
        $this->app->scoped(ResourceRegistry::class);
        $this->app->singleton(ActivityRecorder::class);
        $this->app->singleton(SettingsStore::class);
        $this->app->singleton(HealthChecks::class);
        $this->app->scoped(SyncState::class);

        $config = $this->app->make('config');

        if (! $config->has('auth.guards.'.Fastleo::GUARD)) {
            $config->set('auth.guards.'.Fastleo::GUARD, [
                'driver' => 'session',
                'provider' => $config->string('fastleo.auth.provider', 'users'),
                'remember' => $config->integer('fastleo.auth.remember_days', 30) * 24 * 60,
            ]);
        }

        if (! $config->has('auth.passwords.'.Fastleo::GUARD)) {
            $config->set('auth.passwords.'.Fastleo::GUARD, [
                'provider' => $config->string('auth.guards.'.Fastleo::GUARD.'.provider', 'users'),
                'table' => 'password_reset_tokens',
                'expire' => $config->integer('fastleo.auth.reset_minutes', 60),
                'throttle' => 60,
            ]);
        }
    }

    /**
     * Маршруты, шаблоны `fastleo::`, переводы `fastleo::`, Blade-компоненты `<x-fastleo::...>` (анонимные, из resources/views/components),
     * миграции, публикация конфига и ассетов, artisan-команды; кеш ресурсов — в `optimize`/`optimize:clear`.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom($this->packagePath('routes/fastleo.php'));

        if (config()->boolean('fastleo.sitemap.enabled', false)) {
            $this->loadRoutesFrom($this->packagePath('routes/sitemap.php'));
        }

        if (config()->boolean('fastleo.robots.enabled', false)) {
            $this->loadRoutesFrom($this->packagePath('routes/robots.php'));
        }

        if (SiteClosing::enabled()) {
            SiteClosing::register();
        }

        $this->loadViewsFrom($this->packagePath('resources/views'), 'fastleo');
        $this->loadTranslationsFrom($this->packagePath('resources/lang'), 'fastleo');
        $this->loadMigrationsFrom($this->packagePath('database/migrations'));

        Event::listen(Login::class, [LoginHistory::class, 'handle']);
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'schedule:run') {
                SchedulerPulse::beat();
            }
        });
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *', 'eloquent.restored: *'], function (string $event, array $payload): void {
            if (($payload[0] ?? null) instanceof Model) {
                Sitemap::touched($payload[0]);
            }
        });
        Notification::extend('fastleo', fn (): FastleoChannel => new FastleoChannel);
        View::composer('fastleo::settings.softnetica', fn (\Illuminate\View\View $view) => $view->with('status', Status::current()));
        $this->limitRequests();

        // Заголовки безопасности нужны и редиректу гостя, и ответу 401: Laravel ставит аутентификацию раньше остальных.
        /** @var HttpKernelContract $kernel */
        $kernel = $this->app->make(HttpKernelContract::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->prependToMiddlewarePriority(SecurityHeaders::class);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->packagePath('config/fastleo.php') => config_path('fastleo.php'),
        ], 'fastleo-config');

        $this->publishes([
            Assets::distPath() => public_path(Assets::PUBLIC_PATH),
        ], ['fastleo-assets', 'laravel-assets']);

        $this->commands([
            CacheCommand::class,
            ClearCommand::class,
            DoctorCommand::class,
            ImagesCommand::class,
            InspectCommand::class,
            InstallCommand::class,
            MakeActionCommand::class,
            MakeDashboardCommand::class,
            MakeFieldCommand::class,
            MakePolicyCommand::class,
            MakeSettingsCommand::class,
            MakeFilterCommand::class,
            MakePageCommand::class,
            MakeResourceCommand::class,
            MakeWidgetCommand::class,
            McpCommand::class,
            PruneCommand::class,
            SkillCommand::class,
            SoftNeticaSyncCommand::class,
            UnlockCommand::class,
            UserCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('fastleo:prune')->daily();

            if (Connection::enabled()) {
                $schedule->command('fastleo:softnetica-sync')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer()->runInBackground()->when(fn (): bool => Connection::connected());
            }
        });

        $this->optimizes(optimize: 'fastleo:cache', clear: 'fastleo:clear');
    }

    /**
     * Лимиты запросов: восстановление пароля (по IP и по адресу) и профиль (по администратору — перебор текущего пароля).
     */
    private function limitRequests(): void
    {
        RateLimiter::for('fastleo-password', function (Request $request): array {
            $email = $request->input('email');

            return [
                Limit::perMinute(5)->by('fastleo-password-ip:'.$request->ip()),
                Limit::perHour(10)->by('fastleo-password-email:'.Str::lower(is_string($email) ? trim($email) : '')),
            ];
        });

        // Лимиты по администратору (а не по IP): перебор текущего пароля в профиле и проверки связи с внешними сервисами.
        $adminKey = function (Request $request): string {
            $user = $request->user(Fastleo::GUARD);

            return $user instanceof Authenticatable ? 'id:'.(is_scalar($user->getAuthIdentifier()) ? $user->getAuthIdentifier() : '') : 'ip:'.$request->ip();
        };

        RateLimiter::for('fastleo-profile', fn (Request $request): Limit => Limit::perMinute(5)->by('fastleo-profile:'.$adminKey($request)));
        RateLimiter::for('fastleo-integrations', fn (Request $request): Limit => Limit::perMinute(10)->by('fastleo-integrations:'.$adminKey($request)));
    }

    /**
     * Абсолютный путь внутри пакета.
     */
    private function packagePath(string $path): string
    {
        return __DIR__.'/../'.$path;
    }
}
