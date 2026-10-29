<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Settings;
use Fastleo\SoftNetica\Synchronizer;
use Fastleo\SoftNetica\SyncState;
use Fastleo\Support\SettingsStore;
use Illuminate\Http\RedirectResponse;

/**
 * Действия на странице настроек «SoftNetica»: проверить связь сразу и отключить подключение.
 */
final readonly class SoftNeticaController
{
    /**
     * Проверить связь: выполнить синхронизацию сейчас и показать результат. Запрос уходит и после отказа панели (401):
     * сайт могли снова включить в SoftNetica, не меняя токен.
     */
    public function check(Synchronizer $synchronizer): RedirectResponse
    {
        $this->authorizeSettings();

        $result = $synchronizer->run();

        return $this->back()->with($result->isConnected() ? 'fastleo.success' : 'fastleo.error', $result->label());
    }

    /**
     * Отключить: стереть токен и сохранённые данные клиента.
     */
    public function disconnect(SettingsStore $store, SyncState $state): RedirectResponse
    {
        $settings = $this->authorizeSettings();

        $store->save($settings, ['token' => '']);
        $state->forget();

        return $this->back()->with('fastleo.success', __('fastleo::softnetica.disconnected'));
    }

    /**
     * Страница настроек SoftNetica; выключена в конфиге — 404, нет доступа — 403.
     */
    private function authorizeSettings(): Settings
    {
        $settings = Fastleo::resources()->findSettings('softnetica');
        abort_unless($settings instanceof Settings, 404);
        abort_unless($settings->canAccess(Fastleo::guard()->user()), 403);

        return $settings;
    }

    /**
     * Назад на страницу настроек.
     */
    private function back(): RedirectResponse
    {
        return back(fallback: route('fastleo.settings.edit', 'softnetica'));
    }
}
