<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\ResourceRegistry;
use Fastleo\Support\SettingsStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Кеширует список ресурсов, чтобы на каждом запросе не искать классы в папке.
 * Входит в `php artisan optimize`.
 *
 * ```bash
 * php artisan fastleo:cache
 * ```
 */
#[AsCommand(name: 'fastleo:cache')]
#[Description('Закешировать список разделов (ресурсов) fastleo')]
#[Signature('fastleo:cache')]
final class CacheCommand extends Command
{
    /**
     * Найти ресурсы, проверить их и записать список в bootstrap/cache/fastleo.php (встроенная страница SoftNetica в кеш не входит: её включает конфиг).
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $registry = Fastleo::resources();
        File::delete($registry->cachePath());
        $registry->flush();

        // Проверка адресов всех разделов, настроек и страниц до записи кеша.
        $registry->all();
        $registry->settings();
        $registry->pages();
        $classes = array_values(array_diff($registry->classes(), ResourceRegistry::SOFTNETICA, $registry->builtIn()));
        File::put($registry->cachePath(), '<?php return '.var_export($classes, true).';'.PHP_EOL);
        $registry->flush();

        SettingsStore::forgetCached();

        $this->components->info(__('fastleo::console.cache_done', ['count' => count($classes)]));

        return self::SUCCESS;
    }
}
