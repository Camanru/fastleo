<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\UserModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Первичная установка fastleo на сайт.
 *
 * Публикует конфиг (если его ещё нет) и ассеты, проверяет модель пользователя
 * и очередь, подсказывает следующие шаги.
 *
 * ```bash
 * php artisan fastleo:install
 * ```
 */
#[AsCommand(name: 'fastleo:install')]
#[Description('Установить fastleo: конфиг, ассеты, skill для Claude, проверка модели пользователя и очереди')]
#[Signature('fastleo:install {--skill-path= : папка для skill Claude (по умолчанию .claude/skills/fastleo)}')]
final class InstallCommand extends Command
{
    /**
     * Публикация и проверки. Код выхода 1, если модель пользователя не готова.
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
            $this->components->error(__('fastleo::console.user_model_invalid', ['model' => UserModel::className() ?? '—']));
            $result = self::FAILURE;
        }

        if (config('queue.default') === 'sync') {
            $this->components->warn(__('fastleo::console.install_queue_sync'));
        }

        $this->components->bulletList([
            __('fastleo::console.install_step_migrate'),
            __('fastleo::console.install_step_user'),
            __('fastleo::console.install_step_open', ['url' => route('fastleo.login')]),
        ]);

        return $result;
    }
}
