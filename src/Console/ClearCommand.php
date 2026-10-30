<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\SettingsStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Удаляет кеш списка ресурсов. Входит в `php artisan optimize:clear`.
 *
 * ```bash
 * php artisan fastleo:clear
 * ```
 */
#[AsCommand(name: 'fastleo:clear')]
#[Description('Удалить кеш списка разделов (ресурсов) fastleo')]
#[Signature('fastleo:clear')]
final class ClearCommand extends Command
{
    /**
     * Удалить bootstrap/cache/fastleo.php.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        File::delete(Fastleo::resources()->cachePath());
        Fastleo::resources()->flush();

        SettingsStore::forgetCached();

        $this->components->info(__('fastleo::console.clear_done'));

        return self::SUCCESS;
    }
}
