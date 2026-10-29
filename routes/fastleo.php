<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Http\Controllers\ActionsController;
use Fastleo\Http\Controllers\ActivityController;
use Fastleo\Http\Controllers\AuthController;
use Fastleo\Http\Controllers\DashboardController;
use Fastleo\Http\Controllers\ExportController;
use Fastleo\Http\Controllers\FilesController;
use Fastleo\Http\Controllers\FilterOptionsController;
use Fastleo\Http\Controllers\ImportsController;
use Fastleo\Http\Controllers\LogsController;
use Fastleo\Http\Controllers\MarkdownPreviewController;
use Fastleo\Http\Controllers\MessengerChatsController;
use Fastleo\Http\Controllers\NotificationsController;
use Fastleo\Http\Controllers\OptionsController;
use Fastleo\Http\Controllers\PagesController;
use Fastleo\Http\Controllers\PasswordResetController;
use Fastleo\Http\Controllers\ProfileController;
use Fastleo\Http\Controllers\ResourceController;
use Fastleo\Http\Controllers\SearchController;
use Fastleo\Http\Controllers\SettingsController;
use Fastleo\Http\Controllers\SoftNeticaController;
use Fastleo\Http\Controllers\TwoFactorChallengeController;
use Fastleo\Http\Controllers\TwoFactorController;
use Fastleo\Http\Middleware\SecurityHeaders;
use Fastleo\Http\Middleware\SetLocale;
use Fastleo\Support\ResourceRegistry;
use Illuminate\Support\Facades\Route;

$domain = config('fastleo.domain');

Route::group([
    'prefix' => config()->string('fastleo.path', 'fastleo'),
    'domain' => is_string($domain) && $domain !== '' ? $domain : null,
    'middleware' => [...config()->array('fastleo.middleware', ['web']), SetLocale::class, SecurityHeaders::class],
    'as' => 'fastleo.',
], function (): void {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->name('login.store');

    Route::get('two-factor/challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:fastleo-password')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'form'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:fastleo-password')->name('password.update');

    Route::middleware(Fastleo::adminMiddleware())->group(function (): void {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('clear-cache', [DashboardController::class, 'clearCache'])->name('dashboard.clear-cache');
        Route::post('markdown', MarkdownPreviewController::class)->name('markdown');

        $slugPattern = '[a-z0-9]+(?:-[a-z0-9]+)*';
        Route::get('settings/{page}', [SettingsController::class, 'edit'])->where('page', $slugPattern)->name('settings.edit');
        Route::put('settings/{page}', [SettingsController::class, 'update'])->where('page', $slugPattern)->name('settings.update');
        Route::post('softnetica/check', [SoftNeticaController::class, 'check'])->middleware('throttle:fastleo-integrations')->name('softnetica.check');
        Route::post('softnetica/disconnect', [SoftNeticaController::class, 'disconnect'])->middleware('throttle:fastleo-integrations')->name('softnetica.disconnect');
        Route::get('pages/{page}', [PagesController::class, 'show'])->where('page', $slugPattern)->name('pages.show');
        Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');
        Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationsController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationsController::class, 'read'])->where('id', '[0-9a-f-]{36}')->name('notifications.read');
        Route::get('logs', [LogsController::class, 'index'])->name('logs.index');
        Route::post('logs/clear', [LogsController::class, 'clear'])->name('logs.clear');

        Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
        Route::post('activity/{activity}/revert', [ActivityController::class, 'revert'])->where('activity', '[0-9]{1,18}')->name('activity.revert');

        Route::controller(ProfileController::class)->prefix('profile')->name('profile.')->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::put('/', 'update')->middleware('throttle:fastleo-profile')->name('update');
            Route::put('password', 'password')->middleware('throttle:fastleo-profile')->name('password');
            Route::post('logout-others', 'logoutOthers')->middleware('throttle:fastleo-profile')->name('logout-others');

            Route::controller(TwoFactorController::class)->prefix('two-factor')->middleware('throttle:fastleo-profile')->name('two-factor.')->group(function (): void {
                Route::post('/', 'enable')->name('enable');
                Route::post('confirm', 'confirm')->name('confirm');
                Route::post('recovery-codes', 'recoveryCodes')->name('recovery-codes');
                Route::delete('/', 'disable')->name('disable');
            });
        });

        Route::controller(FilesController::class)->prefix('files')->name('files.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('thumb', 'thumb')->name('thumb');
            Route::post('upload', 'upload')->name('upload');
            Route::post('folder', 'folder')->name('folder');
            Route::post('crop', 'crop')->name('crop');
            Route::delete('/', 'destroy')->name('destroy');
        });

        // Разделы-ресурсы: {resource} — адрес раздела (кроме занятых админкой), {id} — ключ записи (формат проверяет контроллер).
        $reserved = implode('|', ResourceRegistry::RESERVED_SLUGS);

        Route::controller(ResourceController::class)
            ->prefix('{resource}')
            ->name('resources.')
            ->where(['resource' => '(?!(?:'.$reserved.')$)[a-z0-9]+(?:-[a-z0-9]+)*', 'id' => '[0-9A-Za-z-]{1,36}'])
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::post('reorder', 'reorder')->name('reorder');
                Route::get('options/{field}', [OptionsController::class, '__invoke'])->where('field', '[A-Za-z_]\w*')->name('options');
                Route::get('export', [ExportController::class, '__invoke'])->name('export');
                Route::post('import', [ImportsController::class, 'store'])->name('import');
                Route::get('imports/{import}', [ImportsController::class, 'show'])->name('imports.show');
                Route::get('imports/{import}/errors', [ImportsController::class, 'errors'])->name('imports.errors');
                Route::post('actions/{action}', [ActionsController::class, '__invoke'])->where('action', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('action');
                Route::get('filter-options/{filter}', [FilterOptionsController::class, '__invoke'])->where('filter', '[A-Za-z_]\w*')->name('filter-options');
                Route::post('chats/{field}', [MessengerChatsController::class, '__invoke'])->where('field', '[A-Za-z_]\w*')->middleware('throttle:fastleo-integrations')->name('chats');
                Route::get('{id}/history', [ActivityController::class, 'history'])->name('history');
                Route::get('{id}', 'show')->name('show');
                Route::get('{id}/edit', 'edit')->name('edit');
                Route::put('{id}', 'update')->name('update');
                Route::delete('{id}', 'destroy')->name('destroy');
                Route::post('{id}/restore', 'restore')->name('restore');
                Route::delete('{id}/force', 'forceDelete')->name('force-delete');
            });
    });
});
