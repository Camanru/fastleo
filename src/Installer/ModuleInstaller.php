<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Копирует файлы модулей в сайт. Сначала проверяет всё, потом пишет: если занят хоть один файл, таблица или адрес,
 * не записывается ничего, чужой код не перезаписывается.
 *
 * ```php
 * new ModuleInstaller()->install([Modules::find('contacts')]);   // список записанных путей от корня сайта
 * ```
 */
final class ModuleInstaller
{
    /** Тесты модулей, которые не скопированы, потому что на сайте нет Pest (после `install()`). */
    public private(set) bool $testsSkipped = false;

    /**
     * Сообщения о правках `routes/web.php` (после `install()`).
     *
     * @var list<string>
     */
    public private(set) array $notes = [];

    /**
     * Записать файлы модулей.
     *
     * @param  list<Module>  $modules
     * @return list<string> записанные пути от корня сайта
     *
     * @throws ModuleConflictException если что-то занято
     * @throws ModuleWriteException если файл записать не удалось (записанное удалено)
     */
    public function install(array $modules): array
    {
        $conflicts = $this->conflicts($modules);

        if ($conflicts !== []) {
            throw new ModuleConflictException($conflicts);
        }

        $written = [];
        $absolute = [];
        $directories = [];
        $this->notes = [];
        $second = 0;

        try {
            foreach ($this->plan($modules) as $target => $source) {
                if (str_contains($target, '{timestamp}')) {
                    $target = str_replace('{timestamp}', now()->addSeconds($second++)->format('Y_m_d_His'), $target);
                }

                $directory = $this->firstMissingDirectory(dirname($target));

                if ($directory !== null) {
                    $directories[] = $directory;
                }

                File::ensureDirectoryExists(dirname($target));
                File::put($target, strtr(File::get($source), $this->placeholders()));
                $absolute[] = $target;
                $written[] = $this->relative($target);
            }

            if (array_any($written, fn (string $path): bool => str_starts_with($path, 'routes/site/')) && new WebRoutes()->ensureSiteRoutes()) {
                $this->notes[] = __('fastleo::console.module_routes');
            }
        } catch (Throwable $e) {
            File::delete($absolute);

            // Папки, которые создала сама установка, теперь пусты (в них лежали только её файлы).
            foreach ($directories as $directory) {
                File::deleteDirectory($directory);
            }

            throw new ModuleWriteException($e->getMessage(), $e->getCode(), previous: $e);
        }

        foreach ($modules as $module) {
            if ($module->note !== null) {
                $this->notes[] = $module->note;
            }
        }

        $widgets = array_merge(...array_map(fn (Module $module): array => $module->widgets, $modules));

        if ($widgets !== []) {
            $dashboard = new DashboardPatcher;
            $existed = File::exists($dashboard->path());

            array_push($this->notes, ...($dashboard->add($widgets)
                ? [__('fastleo::console.module_dashboard')]
                : [__('fastleo::console.module_dashboard_manual'), ...$widgets]));

            // Файл Dashboard.php создан установкой — он тоже файл сайта, о нём нужно сказать в списке.
            if (! $existed && File::exists($dashboard->path())) {
                $written[] = $this->relative($dashboard->path());
            }
        }

        return $written;
    }

    /**
     * Самая верхняя из ещё не существующих папок на пути к `$directory` или null, если папка уже есть.
     */
    private function firstMissingDirectory(string $directory): ?string
    {
        $missing = null;

        while (! File::isDirectory($directory) && dirname($directory) !== $directory) {
            $missing = $directory;
            $directory = dirname($directory);
        }

        return $missing;
    }

    /**
     * Что мешает установке: занятые файлы, таблицы и адреса.
     *
     * @param  list<Module>  $modules
     * @return list<string>
     */
    public function conflicts(array $modules): array
    {
        return array_merge([], ...array_values($this->conflictsByModule($modules)));
    }

    /**
     * Что мешает установке, по модулям: имя модуля → описания конфликтов (модули без конфликтов не входят).
     *
     * @param  list<Module>  $modules
     * @return array<string, list<string>>
     */
    public function conflictsByModule(array $modules): array
    {
        $conflicts = [];

        foreach ($modules as $module) {
            foreach ($this->files($module->directory) as $target => $source) {
                $skipped = ! $this->hasPest() && str_starts_with($this->relative($target), 'tests/');

                $existing = $skipped ? null : $this->existing($target);

                if ($existing !== null) {
                    $conflicts[$module->name][] = __('fastleo::console.module_conflict_file', ['module' => $module->name, 'path' => $this->relative($existing)]);
                }
            }

            foreach ($module->tables as $table) {
                if ($this->tableExists($table)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_conflict_table', ['module' => $module->name, 'table' => $table]);
                }
            }

            foreach ($module->uris as $uri) {
                if ($this->routeTaken($uri)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_conflict_uri', ['module' => $module->name, 'uri' => '/'.Str::afterLast($uri, ' ')]);
                }
            }
        }

        return $conflicts;
    }

    /**
     * Файлы к записи: путь в сайте => исходная заготовка.
     *
     * @param  list<Module>  $modules
     * @return array<string, string>
     */
    private function plan(array $modules): array
    {
        $plan = [];

        foreach ($modules as $module) {
            $plan += $this->files($module->directory);
        }

        $this->testsSkipped = false;

        if (! $this->hasPest()) {
            foreach (array_keys($plan) as $target) {
                if (str_starts_with($this->relative($target), 'tests/')) {
                    unset($plan[$target]);
                    $this->testsSkipped = true;
                }
            }
        }

        return $plan;
    }

    /**
     * Файлы каталога заготовок: путь в сайте => путь заготовки.
     *
     * @return array<string, string>
     */
    private function files(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory, true) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $relative = str_ends_with($relative, '.stub') ? substr($relative, 0, -5) : $relative;
            $files[$this->target($relative)] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    /**
     * Путь в сайте: `fastleo/…` — папка разделов, остальное — от корня сайта.
     */
    private function target(string $relative): string
    {
        if (str_starts_with($relative, 'fastleo/')) {
            return rtrim(config()->string('fastleo.resources.path'), '/').substr($relative, 7);
        }

        return base_path($relative);
    }

    /**
     * Подстановки в заготовках.
     *
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        return [
            '{{ namespace }}' => trim(config()->string('fastleo.resources.namespace'), '\\'),
            '{{ appNamespace }}' => trim(app()->getNamespace(), '\\'),
        ];
    }

    /**
     * Таблица есть в базе; база недоступна — считаем, что таблицы нет (дальше о базе скажет `migrate`).
     */
    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Адрес сайта уже занят маршрутом. Запись `POST leads` проверяет только маршруты с методом POST.
     */
    private function routeTaken(string $address): bool
    {
        [$method, $uri] = str_contains($address, ' ') ? explode(' ', $address, 2) : [null, $address];

        // Занят и сам адрес, и всё под ним (`news/{id}` у сайта — это тот же раздел `news`).
        return array_any(Route::getRoutes()->getRoutes(), fn (RouteDefinition $route): bool => ($method === null || in_array($method, $route->methods(), true))
            && ($route->uri() === $uri || str_starts_with($route->uri(), $uri.'/')));
    }

    /**
     * На сайте стоит Pest и он инициализирован (`tests/Pest.php`): тесты модулей пишутся на нём и без этого файла падают.
     */
    private function hasPest(): bool
    {
        $composer = base_path('composer.json');

        return File::exists(base_path('tests/Pest.php')) && File::exists($composer) && str_contains(File::get($composer), '"pestphp/pest"');
    }

    /**
     * Занятый путь модуля или null. Миграция модуля называется с меткой `{timestamp}`: занятой считается любая своя миграция
     * с тем же именем (`*_create_leads_table.php`) — иначе `migrate` потом упал бы на второй таблице с тем же названием.
     */
    private function existing(string $target): ?string
    {
        if (! str_contains($target, '{timestamp}')) {
            return File::exists($target) ? $target : null;
        }

        $found = File::glob(str_replace('{timestamp}', '*', $target))[0] ?? null;

        return is_string($found) ? $found : null;
    }

    /**
     * Путь от корня сайта для сообщений.
     */
    private function relative(string $path): string
    {
        return ltrim(str_replace(str_replace('\\', '/', base_path()), '', str_replace('\\', '/', $path)), '/');
    }
}
