<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Копирует файлы модулей в сайт. Сначала проверяет всё, потом пишет: если занят хоть один файл или таблица панельной
 * части, не записывается ничего, чужой код не перезаписывается. Публичная часть (каталог `site/` модуля) ставится по
 * желанию и по модулям: занятый адрес или файл пропускает публичную часть этого модуля, а панельная остаётся.
 *
 * ```php
 * new ModuleInstaller()->install([Modules::find('news')]);                    // панельная и публичная часть
 * new ModuleInstaller()->install([Modules::find('news')], withSite: false);   // только панельная
 * ```
 */
final class ModuleInstaller
{
    /**
     * Сообщения о правках `routes/web.php` (после `install()`).
     *
     * @var list<string>
     */
    public private(set) array $notes = [];

    /**
     * Предупреждения об оставленных шаблонах сайта и непригодном макете (после `install()`).
     *
     * @var list<string>
     */
    public private(set) array $warnings = [];

    /**
     * Модули, чья публичная часть не записана из-за занятого адреса или файла: имя модуля → причины (после `install()`).
     *
     * @var array<string, list<string>>
     */
    public private(set) array $siteSkipped = [];

    /**
     * Записать файлы модулей: панельную часть и, если `$withSite`, публичную часть модулей, у которых ничего не занято.
     *
     * @param  list<Module>  $modules
     * @return list<string> записанные пути от корня сайта
     *
     * @throws ModuleConflictException если что-то занято в панельной части
     * @throws ModuleWriteException если файл записать не удалось (записанное удалено)
     */
    public function install(array $modules, bool $withSite = true): array
    {
        $conflicts = $this->conflicts($modules);

        if ($conflicts !== []) {
            throw new ModuleConflictException($conflicts);
        }

        $written = [];
        $absolute = [];
        $directories = [];
        $this->notes = [];
        $this->warnings = [];
        $this->siteSkipped = [];
        $second = 0;
        $siteModules = $withSite ? $this->siteModules($modules) : [];
        $this->warnings = $this->shadowWarnings($siteModules);

        try {
            foreach ($this->plan($modules, $siteModules) as $target => $source) {
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

            // Маршруты модулей дописываются в конец `routes/web.php` последней записью: до неё всё, что записано, можно откатить.
            $blocks = [];

            foreach ($siteModules as $module) {
                $block = $this->routeBlock($module);

                if ($block !== null) {
                    $blocks[$module->name] = $block;
                }
            }

            if ($blocks !== []) {
                if (! $this->webRoutesConnected()) {
                    $this->warnings[] = __('fastleo::console.module_site_web_unconnected');
                }

                $webRoutes = new WebRoutes();
                $taker = array_find($siteModules, fn (Module $module): bool => $module->takesHome);
                $routes = $webRoutes->append($blocks, $taker !== null, $this->catchAllBlocks());
                $this->notes[] = __('fastleo::console.module_routes', ['modules' => implode(', ', $routes['appended'])]);

                if (array_any($siteModules, fn (Module $module): bool => $module->takesAnySegment)) {
                    array_push($this->warnings, ...$this->anySegmentWarnings());
                }

                if ($taker !== null) {
                    $owners = $this->homeOwners($siteModules, $webRoutes);
                    $home = $this->homeOwner($owners) ?? $taker;
                    $site = $this->routeAt('/');

                    if ($routes['welcomeReplaced']) {
                        $this->notes[] = __('fastleo::console.module_home', ['module' => $home->label]);
                    }

                    if (! $routes['welcomeReplaced'] && $site instanceof RouteDefinition && ! in_array($site->getName(), $this->routeNamesOf($owners), true)) {
                        // Своя главная сайта (не пример Laravel) остаётся и стоит выше: главная ни одного модуля не откроется.
                        $this->warnings[] = __('fastleo::console.module_site_home_taken', ['module' => implode('», «', array_map(fn (Module $module): string => $module->label, $owners !== [] ? $owners : [$taker]))]);
                    } elseif (count($owners) > 1) {
                        // Два модуля отвечают за «/»: открывается главная того, чей маршрут занимает «/» (лендинг), запасной маршрут «Страниц» до «/» не доходит.
                        $others = array_map(fn (Module $module): string => $module->label, array_filter($owners, fn (Module $module): bool => $module !== $home));
                        $this->warnings[] = __('fastleo::console.module_site_home_both', ['owner' => $home->label, 'others' => implode('», «', $others)]);
                    }
                }
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

        foreach ($siteModules as $module) {
            if ($module->siteNote !== null) {
                $this->notes[] = $module->siteNote;
            }
        }

        if ($siteModules !== [] && app()->routesAreCached()) {
            $this->notes[] = __('fastleo::console.module_route_cache');
        }

        // Кеш списка разделов (`fastleo:cache`) не знает новых классов: разделы появятся только после его обновления.
        if (is_file(Fastleo::resources()->cachePath())) {
            $this->notes[] = __('fastleo::console.module_cache');
        }

        $widgets = array_merge(...array_map(fn (Module $module): array => $module->widgets, $modules));

        if ($widgets !== []) {
            $dashboard = new DashboardPatcher();
            $existed = File::exists($dashboard->path());

            // Файл или папка недоступны для записи: модули уже записаны, виджеты остаются на ручное добавление.
            array_push($this->notes, ...(rescue(fn (): bool => $dashboard->add($widgets), false, false)
                ? [__('fastleo::console.module_dashboard')]
                : [__('fastleo::console.module_dashboard_manual'), ...array_map(fn (string $widget): string => strtr($widget, $this->placeholders()), $widgets)]));

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
     * Что мешает установке панельной части: занятые файлы, таблицы и адреса разделов.
     *
     * @param  list<Module>  $modules
     * @return list<string>
     */
    public function conflicts(array $modules): array
    {
        return array_merge([], ...array_values($this->conflictsByModule($modules)));
    }

    /**
     * Что мешает установке панельной части, по модулям: имя модуля → описания конфликтов (модули без конфликтов не входят).
     *
     * @param  list<Module>  $modules
     * @return array<string, list<string>>
     */
    public function conflictsByModule(array $modules): array
    {
        $conflicts = [];

        foreach ($modules as $module) {
            foreach ($this->files($module->directory) as $target => $source) {
                $existing = $this->existing($target);

                if ($existing !== null) {
                    $conflicts[$module->name][] = __('fastleo::console.module_conflict_file', ['module' => $module->name, 'path' => $this->relative($existing)]);
                }
            }

            foreach ($module->tables as $table) {
                if ($this->tableExists($table)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_conflict_table', ['module' => $module->name, 'table' => $table]);
                }
            }

            foreach ($module->slugs as $slug) {
                if ($this->slugTaken($slug)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_conflict_slug', ['module' => $module->name, 'slug' => $slug]);
                }
            }
        }

        return $conflicts;
    }

    /**
     * Что мешает публичной части, по модулям: имя модуля → причины (модули без публичной части и без конфликтов не входят).
     * Свой Blade-шаблон сайта конфликтом не считается: он остаётся как есть.
     *
     * @param  list<Module>  $modules
     * @return array<string, list<string>>
     */
    public function siteConflicts(array $modules): array
    {
        $conflicts = [];
        $web = base_path('routes/web.php');
        $routes = new WebRoutes();
        $panelAtRoot = trim(config()->string('fastleo.path', 'fastleo'), '/') === '' && blank(config('fastleo.domain'));
        $webReadonly = is_file($web) && ! is_writable($web);

        foreach ($modules as $module) {
            $files = array_keys($this->files($module->directory.'/site'));
            $block = $this->routeBlock($module);

            if ($files === [] && $block === null) {
                continue;
            }

            // Маршруты дописываются в `routes/web.php`; без своих маршрутов (меню) модулю этот файл не нужен.
            if ($block !== null) {
                if ($panelAtRoot) {
                    $conflicts[$module->name][] = __('fastleo::console.module_site_panel_root');
                }

                if ($webReadonly) {
                    $conflicts[$module->name][] = __('fastleo::console.module_site_routes_readonly');
                }
            }

            foreach ($files as $target) {
                $relative = $this->relative($target);

                if (! str_starts_with($relative, 'resources/views/') && File::exists($target)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_site_file', ['path' => $relative]);
                }
            }

            // Такая запись уже есть: блок модуля в файле маршрутов второй раз не дописывается, а адреса и имена его маршрутов, конечно, заняты.
            if ($block !== null && $routes->has($block)) {
                $conflicts[$module->name][] = __('fastleo::console.module_site_routes_present', ['module' => $module->name]);

                continue;
            }

            foreach ($module->uris as $uri) {
                if ($this->routeTaken($uri)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_site_uri', ['uri' => '/'.Str::afterLast($uri, ' ')]);
                }
            }

            foreach ($this->routeNames((string) $block) as $name) {
                if (Route::has($name)) {
                    $conflicts[$module->name][] = __('fastleo::console.module_site_route_name', ['name' => $name]);
                }
            }

            if ($module->takesFallback && array_any(Route::getRoutes()->getRoutes(), fn (RouteDefinition $route): bool => $route->isFallback)) {
                $conflicts[$module->name][] = __('fastleo::console.module_site_fallback');
            }
        }

        return $conflicts;
    }

    /**
     * Предупреждения о своих маршрутах сайта, которые перекроют адреса публичной части: маршруты модулей подключаются в
     * конец `routes/web.php`, и побеждает тот, что зарегистрирован раньше (`/{slug}` без `where` забирает `/news`, а
     * `/{category}/{slug}` — страницы записей `/news/…`).
     *
     * @param  list<Module>  $siteModules
     * @return list<string>
     */
    private function shadowWarnings(array $siteModules): array
    {
        $warnings = [];
        $routes = new WebRoutes();
        // Маршрут, подходящий под любой адрес из одного сегмента (лендинг), стоит в конце файла, ниже блоков, поставленных позже: он их не перекрывает.
        $wide = $this->routeNamesOf(array_values(array_filter(Modules::all(), fn (Module $module): bool => $module->takesAnySegment && ($block = $this->routeBlock($module)) !== null && $routes->has($block))));

        foreach ($siteModules as $module) {
            $addresses = array_map(fn (string $address): array => str_contains($address, ' ') ? explode(' ', $address, 2) : ['GET', $address], $module->uris);

            // Страницы записей: адрес с параметром (`news/{slug}`) проверяется на подставленном значении.
            foreach ($this->routeAddresses((string) $this->routeBlock($module)) as [$method, $uri]) {
                if (str_contains($uri, '{')) {
                    $addresses[] = [$method, $uri];
                }
            }

            foreach ($addresses as [$method, $uri]) {
                // Параметр подставляется цифрой и буквой: свой маршрут сайта с `where('slug', '[a-z-]+')` цифру не принимает.
                $route = $this->routeAt('/'.preg_replace('~\{[^}]+\}~', '1', $uri), $method) ?? $this->routeAt('/'.preg_replace('~\{[^}]+\}~', 'a', $uri), $method);

                if ($route instanceof RouteDefinition && ! in_array($route->getName(), $wide, true)) {
                    $warnings[] = __('fastleo::console.module_site_shadowed', ['route' => $route->uri(), 'uri' => $uri, 'module' => $module->name]);
                }
            }
        }

        return $warnings;
    }

    /**
     * Предупреждения для маршрута, который подходит под любой адрес из одного сегмента (лендинг): он стоит в конце
     * `routes/web.php`, поэтому закроет адреса из файлов маршрутов, что грузятся после него, а запасной маршрут сайта
     * выполняет без его middleware.
     *
     * @return list<string>
     */
    private function anySegmentWarnings(): array
    {
        $warnings = [];
        $web = File::exists(base_path('routes/web.php')) ? File::get(base_path('routes/web.php')) : '';
        // Файлы, подключённые из самого web.php (`require __DIR__.'/auth.php'`, стартовые наборы Laravel), лежат выше блока лендинга.
        $files = array_filter(
            array_map($this->relative(...), glob(base_path('routes/*.php')) ?: []),
            fn (string $path): bool => ! in_array($path, ['routes/web.php', 'routes/console.php', 'routes/channels.php', 'routes/api.php'], true) && ! str_contains($web, basename($path)),
        );
        $bootstrap = base_path('bootstrap/app.php');

        // Комментарий «// then: …» не считается: смотрим код без комментариев.
        if (is_file($bootstrap) && preg_match('~\bthen\s*:~', WebRoutes::withoutComments(File::get($bootstrap))) === 1) {
            $files[] = 'bootstrap/app.php (then:)';
        }

        if ($files !== []) {
            $warnings[] = __('fastleo::console.module_site_other_routes', ['files' => implode(', ', $files)]);
        }

        $fallback = array_find(Route::getRoutes()->getRoutes(), fn (RouteDefinition $route): bool => $route->isFallback);
        // Только объявленные на маршруте middleware: `gatherMiddleware()` создал бы контроллер сайта, а его конструктор может упасть (например, до migrate).
        $middleware = array_values(array_diff(array_filter($fallback?->middleware() ?? [], is_string(...)), ['web']));

        if ($middleware !== []) {
            $warnings[] = __('fastleo::console.module_site_fallback_middleware', ['middleware' => implode('», «', $middleware)]);
        }

        return $warnings;
    }

    /**
     * Адреса маршрутов в коде блока: метод и адрес без начального `/`.
     *
     * @return list<array{string, string}>
     */
    private function routeAddresses(string $block): array
    {
        preg_match_all("~Route::(get|post|put|patch|delete)\\(\\s*'/?([^']*)'~i", $block, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [strtoupper($match[1]), $match[2]], $matches);
    }

    /**
     * Модули, которые отвечают за «/»: берущие главную (`takesHome`) и уже записавшие свой блок маршрутов в `routes/web.php`
     * (в этом запуске или раньше). Модули этого запуска стоят в списке вместо одноимённых из каталога.
     *
     * @param  list<Module>  $siteModules
     * @return list<Module>
     */
    private function homeOwners(array $siteModules, WebRoutes $webRoutes): array
    {
        $candidates = [];

        foreach ([...$siteModules, ...Modules::all()] as $module) {
            $candidates[$module->name] ??= $module;
        }

        return array_values(array_filter($candidates, function (Module $module) use ($webRoutes): bool {
            $block = $module->takesHome ? $this->routeBlock($module) : null;

            return $block !== null && $webRoutes->has($block);
        }));
    }

    /**
     * Имена маршрутов блоков этих модулей (`site.landing.show`): по ним отличают маршрут модуля от своего маршрута сайта.
     *
     * @param  list<Module>  $modules
     * @return list<string>
     */
    private function routeNamesOf(array $modules): array
    {
        return array_merge(...array_map(fn (Module $module): array => $this->routeNames((string) $this->routeBlock($module)), $modules));
    }

    /**
     * Модуль, чья главная откроется по «/»: тот, что занимает «/» собственным маршрутом (без запасного), иначе первый.
     *
     * @param  list<Module>  $owners
     */
    private function homeOwner(array $owners): ?Module
    {
        return array_find($owners, fn (Module $module): bool => ! $module->takesFallback) ?? ($owners[0] ?? null);
    }

    /**
     * Код блоков маршрутов, которые должны стоять в конце `routes/web.php`: запасной маршрут страниц и маршрут лендинга, который
     * подходит под любой адрес из одного сегмента.
     *
     * @return list<string>
     */
    private function catchAllBlocks(): array
    {
        $blocks = fn (Closure $take): array => array_filter(array_map(fn (Module $module): ?string => $take($module) ? $this->routeBlock($module) : null, Modules::all()));

        // Сначала маршрут для любого одного сегмента, самым последним — запасной маршрут: он подходит под любой адрес.
        return array_values([...$blocks(fn (Module $module): bool => $module->takesAnySegment), ...$blocks(fn (Module $module): bool => $module->takesFallback)]);
    }

    /**
     * В `bootstrap/app.php` подключён `routes/web.php`; сайту без `web:` в `withRouting()` (только API) маршруты модулей не помогут.
     */
    private function webRoutesConnected(): bool
    {
        $bootstrap = base_path('bootstrap/app.php');

        return ! File::exists($bootstrap) || str_contains(File::get($bootstrap), 'routes/web.php');
    }

    /**
     * Маршрут сайта, который ответит на запрос по адресу (запасной маршрут `Route::fallback` не считается).
     */
    private function routeAt(string $uri, string $method = 'GET'): ?RouteDefinition
    {
        $request = Request::create($uri, $method);

        return array_find(Route::getRoutes()->getRoutes(), fn (RouteDefinition $route): bool => ! $route->isFallback && $route->matches($request));
    }

    /**
     * Модули, публичную часть которых можно записать: она есть и ничего не занято. Причины пропуска остаются в `siteSkipped`.
     *
     * @param  list<Module>  $modules
     * @return list<Module>
     */
    private function siteModules(array $modules): array
    {
        $this->siteSkipped = $this->siteConflicts($modules);

        return array_values(array_filter($modules, fn (Module $module): bool => ! isset($this->siteSkipped[$module->name]) && ($this->files($module->directory.'/site') !== [] || $this->routeBlock($module) !== null)));
    }

    /**
     * Файлы к записи: путь в сайте => исходная заготовка. Свой шаблон сайта не перезаписывается.
     *
     * @param  list<Module>  $modules
     * @param  list<Module>  $siteModules  модули, чья публичная часть записывается
     * @return array<string, string>
     */
    private function plan(array $modules, array $siteModules): array
    {
        $plan = [];

        foreach ($modules as $module) {
            $plan += $this->files($module->directory);
        }

        if ($siteModules !== []) {
            // Общий макет кладётся, только если его ещё нет: у второго модуля он уже будет.
            $site = $this->files(Modules::sharedDirectory());

            foreach ($siteModules as $module) {
                $site += $this->files($module->directory.'/site');
            }

            foreach ($site as $target => $source) {
                if (! File::exists($target)) {
                    $plan[$target] = $source;
                } else {
                    $this->keepExisting($target);
                }
            }
        }

        return $plan;
    }

    /**
     * Свой шаблон сайта остаётся: предупреждение об этом, а у макета — о том, что страница модуля в нём не выведется.
     */
    private function keepExisting(string $target): void
    {
        $relative = $this->relative($target);

        if ($target === base_path('resources/views/layouts/site.blade.php')) {
            if (preg_match('/@yield\(\s*[\'"]content[\'"]/', File::get($target)) !== 1) {
                $this->warnings[] = __('fastleo::console.module_site_layout', ['path' => $relative]);
            }
        } elseif (str_starts_with($relative, 'resources/views/')) {
            $this->warnings[] = __('fastleo::console.module_site_kept', ['path' => $relative]);
        }
    }

    /**
     * Файлы каталога заготовок: путь в сайте => путь заготовки. Каталог `site/` модуля (публичная часть) не входит:
     * он берётся отдельно, как свой каталог. Нет каталога — нет файлов.
     *
     * @return array<string, string>
     */
    private function files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($directory, true) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            // Заготовка маршрутов не файл сайта: её код дописывается в `routes/web.php` (`routeBlock()`).
            if (str_starts_with($relative, 'site/') || $relative === 'routes/web.php.stub') {
                continue;
            }

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
     * Код маршрутов публичной части модуля с подстановками (без `<?php`) или null, если у модуля нет своих маршрутов.
     */
    private function routeBlock(Module $module): ?string
    {
        $source = $module->directory.'/site/routes/web.php.stub';

        return is_file($source) ? strtr(File::get($source), $this->placeholders()) : null;
    }

    /**
     * Имена маршрутов в коде блока: `->name('site.news.index')`.
     *
     * @return list<string>
     */
    private function routeNames(string $block): array
    {
        preg_match_all("~->name\\('([^']+)'\\)~", $block, $matches);

        return $matches[1];
    }

    /**
     * Подстановки в заготовках.
     *
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        try {
            $appNamespace = app()->getNamespace();
        } catch (RuntimeException $e) {
            throw new ModuleWriteException(__('fastleo::console.module_namespace_unknown'), $e->getCode(), previous: $e);
        }

        return [
            '{{ namespace }}' => trim(config()->string('fastleo.resources.namespace'), '\\'),
            '{{ appNamespace }}' => trim($appNamespace, '\\'),
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
     * Адрес раздела или страницы настроек уже занят разделом сайта.
     */
    private function slugTaken(string $slug): bool
    {
        $registry = Fastleo::resources();

        return isset($registry->all()[$slug]) || isset($registry->settings()[$slug]);
    }

    /**
     * Адрес сайта уже занят маршрутом. Запись `POST shop` проверяет только маршруты с методом POST.
     */
    private function routeTaken(string $address): bool
    {
        [$method, $uri] = str_contains($address, ' ') ? explode(' ', $address, 2) : [null, $address];

        // Занят и сам адрес, и всё под ним (`news/{id}` у сайта — это тот же раздел `news`).
        return array_any(Route::getRoutes()->getRoutes(), fn (RouteDefinition $route): bool => ($method === null || in_array($method, $route->methods(), true))
            && ($route->uri() === $uri || str_starts_with($route->uri(), $uri.'/')));
    }

    /**
     * На сайте стоит Pest и он инициализирован (`tests/Pest.php`): вместе с модулем на сайт копируются его тесты, они
     * написаны на Pest и без этого файла падают.
     */
    public function hasPest(): bool
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

        // Путь сайта может содержать `[`, `*`, `?`: в шаблоне glob они экранируются, подставляется только метка времени.
        $found = File::glob(implode('*', array_map(fn (string $part): string => addcslashes($part, '[]*?\\'), explode('{timestamp}', $target, 2))))[0] ?? null;

        return is_string($found) ? $found : null;
    }

    /**
     * Путь от корня сайта для сообщений.
     */
    private function relative(string $path): string
    {
        // Убирается только начало: базовый путь вроде `/app` встречается и внутри пути (`/app/app/Fastleo`).
        return Str::chopStart(str_replace('\\', '/', $path), str_replace('\\', '/', base_path()).'/');
    }
}
