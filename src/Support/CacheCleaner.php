<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/**
 * Очистка кеша сайта кнопкой на дашборде: `php artisan optimize:clear` (кеш приложения, конфигурации, маршрутов,
 * событий, шаблонов и список разделов fastleo). Размер освобождаемого места считается заранее.
 *
 * Кеш Redis, базы данных и т. п. в размер не входит — считаются только файлы на диске сайта.
 */
final class CacheCleaner
{
    /**
     * Админу можно очищать кеш: Gate `clearFastleoCache`, если сайт его описал, иначе можно любому админу.
     *
     * ```php
     * Gate::define('clearFastleoCache', fn (User $user) => $user->is_owner);
     * ```
     */
    public static function canClear(): bool
    {
        return ! Gate::has('clearFastleoCache') || Gate::forUser(Fastleo::guard()->user())->allows('clearFastleoCache');
    }

    /**
     * Сколько байт занимают файлы кеша на диске.
     */
    public static function size(): int
    {
        $size = 0;

        foreach (self::files() as $file) {
            $size += is_file($file) ? (int) filesize($file) : 0;
        }

        foreach (self::directories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (Finder::create()->files()->in($directory)->ignoreDotFiles(true) as $file) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Очистить кеш и вернуть, сколько байт освободилось.
     */
    public static function clear(): int
    {
        $size = self::size();

        Artisan::call('optimize:clear');

        return $size;
    }

    /**
     * Размер человеческим языком: «3,1 МБ».
     */
    public static function format(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, 1, ',', ' ')).' '.$units[$unit];
    }

    /**
     * Файлы кеша внутри `bootstrap/cache`.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        return [
            app()->getCachedConfigPath(),
            app()->getCachedRoutesPath(),
            app()->getCachedEventsPath(),
            Fastleo::resources()->cachePath(),
        ];
    }

    /**
     * Папки кеша: скомпилированные шаблоны и файловый кеш приложения.
     *
     * @return list<string>
     */
    private static function directories(): array
    {
        $directories = [config()->string('view.compiled', '')];
        $store = config()->string('cache.default', 'file');

        if (config('cache.stores.'.$store.'.driver') === 'file') {
            $directories[] = config()->string('cache.stores.'.$store.'.path', '');
        }

        return array_values(array_filter($directories, fn (string $directory): bool => $directory !== ''));
    }
}
