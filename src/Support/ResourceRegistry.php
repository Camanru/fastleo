<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Closing\ClosingSettings;
use Fastleo\Closing\SiteClosing;
use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Resource;
use Fastleo\Settings;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\SoftNeticaPage;
use Fastleo\SoftNetica\SoftNeticaSettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Реестр ресурсов сайта: находит классы в `config('fastleo.resources.path')` или читает их список
 * из кеша `bootstrap/cache/fastleo.php` (`php artisan fastleo:cache`, входит в `optimize`).
 *
 * ```php
 * Fastleo::resources()->all();          // ['doctors' => DoctorResource::class, ...]
 * Fastleo::resources()->find('doctors'); // объект DoctorResource или null
 * ```
 */
final class ResourceRegistry
{
    /** Встроенные классы SoftNetica: добавляются при каждом запросе по конфигу, в кеш разделов не входят. */
    public const array SOFTNETICA = [SoftNeticaSettings::class, SoftNeticaPage::class];

    /** Встроенные страницы, которые добавляются при каждом запросе по конфигу (SoftNetica, «Закрытие сайта»): в кеш разделов не входят. */
    public const array RUNTIME = [...self::SOFTNETICA, ClosingSettings::class];

    /** Ключи конфига со встроенными разделами (класс ресурса или `null` — раздела нет). */
    public const array BUILT_IN = ['fastleo.notifications.bots', 'fastleo.publishing.channels', 'fastleo.publishing.publications'];

    /**
     * Адреса, которые админка занимает сама или займёт на следующих этапах, — их нельзя брать ресурсу.
     */
    public const array RESERVED_SLUGS = [
        'login', 'logout', 'files', 'search', 'settings', 'pages', 'logs', 'notifications',
        'profile', 'activity', 'two-factor', 'forgot-password', 'reset-password', 'sitemap', 'markdown', 'clear-cache', 'softnetica',
    ];

    /**
     * Ресурсы по адресу раздела.
     *
     * @var array<string, class-string<Resource>>|null
     */
    private ?array $resources = null;

    /**
     * Созданные объекты ресурсов.
     *
     * @var array<string, Resource>
     */
    private array $instances = [];

    /**
     * Страницы настроек по адресу.
     *
     * @var array<string, class-string<Settings>>|null
     */
    private ?array $settings = null;

    /**
     * Свои страницы по адресу.
     *
     * @var array<string, class-string<Page>>|null
     */
    private ?array $pages = null;

    /**
     * Созданные объекты страниц настроек и своих страниц.
     *
     * @var array<string, Settings|Page|null>
     */
    private array $extras = [];

    public function __construct(private readonly Application $app) {}

    /**
     * Все ресурсы: адрес раздела → класс.
     *
     * @return array<string, class-string<Resource>>
     *
     * @throws LogicException ошибка в описании ресурса (нет модели, занятый или повторный адрес)
     */
    public function all(): array
    {
        return $this->resources ??= $this->index(array_values(array_filter(
            $this->classes(),
            fn (string $class): bool => ! class_exists($class) || ! is_subclass_of($class, Settings::class) && ! is_subclass_of($class, Page::class),
        )));
    }

    /**
     * Страницы настроек: адрес → класс.
     *
     * @return array<string, class-string<Settings>>
     *
     * @throws LogicException недопустимый или повторный адрес
     */
    public function settings(): array
    {
        return $this->settings ??= $this->indexExtras(Settings::class);
    }

    /**
     * Свои страницы: адрес → класс.
     *
     * @return array<string, class-string<Page>>
     *
     * @throws LogicException недопустимый или повторный адрес
     */
    public function pages(): array
    {
        return $this->pages ??= $this->indexExtras(Page::class);
    }

    /**
     * Страница настроек по адресу или null.
     */
    public function findSettings(string $slug): ?Settings
    {
        $class = $this->settings()[$slug] ?? null;
        $key = 'settings:'.$slug;

        if ($class !== null && ! isset($this->extras[$key])) {
            $page = $this->app->make($class);
            $this->extras[$key] = $page instanceof Settings ? $page : null;
        }

        $page = $this->extras[$key] ?? null;

        return $page instanceof Settings ? $page : null;
    }

    /**
     * Своя страница по адресу или null.
     */
    public function findPage(string $slug): ?Page
    {
        $class = $this->pages()[$slug] ?? null;
        $key = 'page:'.$slug;

        if ($class !== null && ! isset($this->extras[$key])) {
            $page = $this->app->make($class);
            $this->extras[$key] = $page instanceof Page ? $page : null;
        }

        $page = $this->extras[$key] ?? null;

        return $page instanceof Page ? $page : null;
    }

    /**
     * Ресурс по адресу раздела или null.
     */
    public function find(string $slug): ?Resource
    {
        $class = $this->all()[$slug] ?? null;

        if ($class === null) {
            return null;
        }

        if (! isset($this->instances[$slug])) {
            $resource = $this->app->make($class);

            if (! $resource instanceof Resource) {
                throw new LogicException($this->message('resource_missing', ['class' => $class]));
            }

            $this->instances[$slug] = $resource;
        }

        return $this->instances[$slug];
    }

    /**
     * Классы ресурсов, страниц настроек и своих страниц: из кеша, если он есть, иначе поиском по папке.
     *
     * @return list<class-string<Resource|Settings|Page>>
     */
    public function classes(): array
    {
        if (is_file($this->cachePath())) {
            /** @var list<class-string<Resource>> $classes */
            $classes = require $this->cachePath();

            return $this->withBuiltIn($this->withSoftNetica($classes));
        }

        return $this->withBuiltIn($this->withSoftNetica($this->discover()));
    }

    /**
     * Встроенные разделы из конфига (`BUILT_IN`): «Уведомления» и другие. Добавляются при каждом запросе,
     * в кеш разделов не входят; раздел с тем же адресом у сайта важнее (встроенный пропускается).
     *
     * @return list<class-string<Resource>>
     */
    public function builtIn(): array
    {
        $classes = [];

        foreach (self::BUILT_IN as $key) {
            $class = config($key);

            if (is_string($class) && class_exists($class) && is_subclass_of($class, Resource::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Добавить встроенные разделы в конец списка (после разделов сайта).
     *
     * @param  list<class-string<Resource|Settings|Page>>  $classes
     * @return list<class-string<Resource|Settings|Page>>
     */
    private function withBuiltIn(array $classes): array
    {
        $builtIn = $this->builtIn();

        return [...array_values(array_diff($classes, $builtIn)), ...$builtIn];
    }

    /**
     * Добавить встроенные страницы настроек SoftNetica и «Закрытие сайта», если функции включены в конфиге. Проверяется при каждом
     * запросе, а не при сборке кеша: выключатели действуют и на сайте с `fastleo:cache`.
     *
     * @param  list<class-string<Resource|Settings|Page>>  $classes
     * @return list<class-string<Resource|Settings|Page>>
     */
    private function withSoftNetica(array $classes): array
    {
        $classes = array_values(array_diff($classes, self::RUNTIME));

        if (Connection::enabled()) {
            array_push($classes, ...self::SOFTNETICA);
        }

        if (SiteClosing::enabled()) {
            $classes[] = ClosingSettings::class;
        }

        return $classes;
    }

    /**
     * Найти классы ресурсов, страниц настроек и своих страниц в папке `fastleo.resources.path`
     * с namespace `fastleo.resources.namespace` (PSR-4).
     *
     * @return list<class-string<Resource|Settings|Page>>
     */
    public function discover(): array
    {
        $path = config()->string('fastleo.resources.path');
        $namespace = trim(config()->string('fastleo.resources.namespace'), '\\');
        $classes = [];

        if (! is_dir($path)) {
            return $this->withUsers($classes);
        }

        foreach (Finder::create()->files()->in($path)->name('*.php')->sortByName() as $file) {
            $class = $namespace.'\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));

            if (class_exists($class) && (is_subclass_of($class, Resource::class) || is_subclass_of($class, Settings::class) || is_subclass_of($class, Page::class)) && ! new ReflectionClass($class)->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $this->withUsers($classes);
    }

    /**
     * Добавить раздел администраторов из `fastleo.users.resource` (если он включён, подходит модель
     * пользователей и сайт не описал тот же класс сам).
     *
     * @param  list<class-string<Resource|Settings|Page>>  $classes
     * @return list<class-string<Resource|Settings|Page>>
     */
    private function withUsers(array $classes): array
    {
        $class = config('fastleo.users.resource');

        if (is_string($class) && class_exists($class) && is_subclass_of($class, Resource::class) && $class::hasModel() && ! in_array($class, $classes, true)) {
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Путь к файлу кеша.
     */
    public function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/fastleo.php');
    }

    /**
     * Забыть найденные ресурсы (после записи или удаления кеша).
     */
    public function flush(): void
    {
        $this->resources = null;
        $this->instances = [];
        $this->settings = null;
        $this->pages = null;
        $this->extras = [];
    }

    /**
     * Проверить ресурсы и разложить по адресам.
     *
     * @param  list<class-string>  $classes
     * @return array<string, class-string<Resource>>
     *
     * @throws LogicException ошибка в описании ресурса
     */
    private function index(array $classes): array
    {
        $resources = [];
        $builtIn = $this->builtIn();

        foreach ($classes as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Resource::class)) {
                throw new LogicException($this->message('resource_missing', ['class' => $class]));
            }

            if (! $class::hasModel() || ! is_subclass_of($class::model(), Model::class)) {
                throw new LogicException($this->message('resource_model', ['class' => $class]));
            }

            $slug = $class::slug();

            if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
                throw new LogicException($this->message('resource_slug', ['class' => $class, 'slug' => $slug]));
            }

            if (in_array($slug, self::RESERVED_SLUGS, true)) {
                throw new LogicException($this->message('resource_slug_reserved', ['class' => $class, 'slug' => $slug]));
            }

            foreach ([$class::sortColumn(), $class::treeColumn()] as $column) {
                if ($column !== null && preg_match('/^[A-Za-z_]\w*$/', $column) !== 1) {
                    throw new LogicException($this->message('resource_column', ['class' => $class, 'column' => $column]));
                }
            }

            if (isset($resources[$slug]) && in_array($class, $builtIn, true)) {
                continue;
            }

            if (isset($resources[$slug])) {
                throw new LogicException($this->message('resource_slug_duplicate', ['class' => $class, 'other' => $resources[$slug], 'slug' => $slug]));
            }

            $resources[$slug] = $class;
        }

        return $resources;
    }

    /**
     * Страницы настроек или своих страниц по адресам: проверка адреса и повторов.
     *
     * @template T of Settings|Page
     *
     * @param  class-string<T>  $base
     * @return array<string, class-string<T>>
     *
     * @throws LogicException недопустимый или повторный адрес
     */
    private function indexExtras(string $base): array
    {
        $result = [];

        foreach ($this->classes() as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, $base)) {
                continue;
            }

            $slug = $class::slug();

            if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
                throw new LogicException($this->message('page_slug', ['class' => $class, 'slug' => $slug]));
            }

            if (isset($result[$slug])) {
                throw new LogicException($this->message('page_slug_duplicate', ['class' => $class, 'other' => $result[$slug], 'slug' => $slug]));
            }

            $result[$slug] = $class;
        }

        return $result;
    }

    /**
     * Ресурс модели-дерева (с `$treeColumn`) для класса модели или null.
     *
     * @param  class-string<Model>  $model
     */
    public function treeFor(string $model): ?Resource
    {
        foreach ($this->all() as $slug => $class) {
            if ($class::model() === $model && $class::treeColumn() !== null) {
                return $this->find($slug);
            }
        }

        return null;
    }

    /**
     * Текст ошибки для разработчика.
     *
     * @param  array<string, string>  $replace
     */
    private function message(string $key, array $replace): string
    {
        return __('fastleo::exceptions.'.$key, $replace, Fastleo::LOCALE);
    }
}
