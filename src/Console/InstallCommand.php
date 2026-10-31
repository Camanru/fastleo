<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\Doctor;
use Fastleo\Support\UserModel;
use Fastleo\Support\UserModelPatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Первичная установка fastleo на сайт.
 *
 * Публикует конфиг (если его ещё нет) и ассеты, подключает модель пользователя
 * (интерфейс FastleoUser и трейт HasFastleoAccess), проверяет очередь, подсказывает следующие шаги.
 *
 * ```bash
 * php artisan fastleo:install
 * ```
 */
#[AsCommand(name: 'fastleo:install')]
#[Description('Установить fastleo: конфиг, ассеты, skill для Claude, подключение модели пользователя, проверка очереди')]
#[Signature('fastleo:install {--skill-path= : папка для skill Claude (по умолчанию .claude/skills/fastleo)}')]
final class InstallCommand extends Command
{
    /**
     * Публикация и проверки. Код выхода 1, если модель пользователя не готова и подключить её автоматически не вышло.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $this->callSilently('vendor:publish', ['--tag' => 'fastleo-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
        $this->callSilently('fastleo:skill', ['--path' => $this->option('skill-path')]);
        $this->components->info(__('fastleo::console.install_published'));

        $result = self::SUCCESS;

        if (UserModel::fastleoClassName() === null) {
            $model = UserModel::className();
            $file = $model !== null && is_a($model, Model::class, true) ? UserModelPatcher::connect($model) : null;

            if ($file !== null) {
                $this->components->info(__('fastleo::console.install_model_connected', ['model' => $model, 'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file)]));
            } else {
                $this->components->error(__('fastleo::console.install_model_manual', ['model' => $model ?? '—']));
                $result = self::FAILURE;
            }
        }

        if (config('queue.default') === 'sync') {
            $this->components->warn(__('fastleo::console.install_queue_sync'));
        }

        $this->components->bulletList(array_values(array_filter([
            Doctor::packageMigrationsPending() ? __('fastleo::console.install_step_migrate') : null,
            Doctor::storageLinkMissing() ? __('fastleo::console.install_step_storage') : null,
            __('fastleo::console.install_step_user'),
            __('fastleo::console.install_step_open', ['url' => route('fastleo.login')]),
        ])));

        return $result;
    }
}
