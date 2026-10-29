<?php

declare(strict_types=1);

namespace Fastleo\Support;

use DateTimeInterface;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Seo;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/**
 * Карта сайта `/sitemap.xml`: адреса записей разделов, у которых описан `sitemap()`, и свои адреса из конфига.
 *
 * Адреса приводятся к `APP_URL` (по протоколу карты все адреса — на одном домене с картой), записи
 * с запретом индексации в поле Seo пропускаются. В одном файле не больше 50 000 адресов (`sitemap.per_file`);
 * если их больше, `/sitemap.xml` становится индексом со ссылками на `/sitemap-1.xml`, `/sitemap-2.xml` и так далее
 * (всего до 10 файлов). Готовая карта лежит в кеше `sitemap.cache_minutes` минут (каждый файл под своим ключом:
 * в кеше в базе значение ограничено 16 МБ) и сбрасывается при сохранении, удалении и восстановлении записи таких разделов.
 */
final class Sitemap
{
    /** Предел адресов одного файла карты по протоколу. */
    public const int LIMIT = 50000;

    /** Сколько файлов карты строится самое большее: адреса сверх этого пропускаются. */
    public const int MAX_FILES = 10;

    private const string CACHE_KEY = 'fastleo.sitemap';

    private const string MODELS_KEY = 'fastleo.sitemap.models';

    private const string PAGE_KEY = 'fastleo.sitemap.page.';

    /**
     * XML карты по адресу `/sitemap.xml` (из кеша, если он включён): сама карта или, если адресов много, индекс файлов.
     */
    public static function xml(): string
    {
        $meta = self::meta();

        return $meta === null ? self::rebuild()['main'] : $meta['main'];
    }

    /**
     * XML файла `/sitemap-{page}.xml` (нумерация с 1) или null, если такого файла нет. Файлы есть, только когда
     * `/sitemap.xml` — индекс.
     */
    public static function page(int $page): ?string
    {
        $meta = self::meta();

        if ($meta !== null) {
            if ($page < 1 || $page > $meta['files']) {
                return null;
            }

            $cached = Cache::get(self::PAGE_KEY.$page);

            if (is_string($cached)) {
                return $cached;
            }
        }

        return self::rebuild()['pages'][$page - 1] ?? null;
    }

    /**
     * Запись модели изменилась — сбросить карту, если модель в ней участвует.
     */
    public static function touched(Model $model): void
    {
        if (! config()->boolean('fastleo.sitemap.enabled', false)) {
            return;
        }

        $models = Cache::get(self::MODELS_KEY);

        if (is_array($models) && in_array($model::class, $models, true)) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    /**
     * Главная запись кеша: XML `/sitemap.xml` и число файлов (`files` — 0, когда карта в одном файле). Null, если кеша нет.
     *
     * @return array{main: string, files: int}|null
     */
    private static function meta(): ?array
    {
        if (config()->integer('fastleo.sitemap.cache_minutes', 60) <= 0) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) && is_string($cached['main'] ?? null) && is_int($cached['files'] ?? null)
            ? ['main' => $cached['main'], 'files' => $cached['files']]
            : null;
    }

    /**
     * Собрать карту заново и, если кеш включён, положить в него: сначала файлы, потом главную запись, чтобы
     * главная запись никогда не указывала на ещё не записанные файлы.
     *
     * @return array{main: string, pages: list<string>}
     */
    private static function rebuild(): array
    {
        $built = self::build();
        $minutes = config()->integer('fastleo.sitemap.cache_minutes', 60);

        if ($minutes > 0) {
            $until = now()->addMinutes($minutes);

            foreach ($built['pages'] as $index => $xml) {
                Cache::put(self::PAGE_KEY.($index + 1), $xml, $until);
            }

            Cache::put(self::CACHE_KEY, ['main' => $built['main'], 'files' => count($built['pages'])], $until);
        }

        return $built;
    }

    /**
     * Собрать карту заново: адреса раскладываются по файлам `sitemap.per_file`. Если файл один, он и есть `main`,
     * иначе `main` — индекс, а файлы лежат в `pages`.
     *
     * @return array{main: string, pages: list<string>}
     */
    private static function build(): array
    {
        $files = new SitemapFiles(max(1, min(self::LIMIT, config()->integer('fastleo.sitemap.per_file', self::LIMIT))), self::MAX_FILES);
        $models = [];

        foreach (array_keys(Fastleo::resources()->all()) as $slug) {
            $resource = Fastleo::resources()->find($slug);

            if (! $resource instanceof Resource) {
                continue;
            }

            $query = $resource->sitemap($resource->newModel()->newQuery());

            if (! $query instanceof Builder) {
                continue;
            }

            $models[] = $resource::model();
            $noindex = collect($resource->schema()->fields())->first(fn (Field $field): bool => $field instanceof Seo)?->name;

            foreach ($query->reorder()->lazyById(500) as $record) {
                if ($files->isFull()) {
                    break;
                }

                $url = $resource->url($record);

                if ($url === null || $url === '' || self::isHidden($record, $noindex)) {
                    continue;
                }

                $date = $record->getAttribute('updated_at');
                $files->add(self::normalize($url), $date instanceof DateTimeInterface ? $date->format('c') : null);
            }
        }

        foreach (config()->array('fastleo.sitemap.urls', []) as $entry) {
            $loc = is_array($entry) ? ($entry['loc'] ?? null) : $entry;
            $lastmod = is_array($entry) && is_string($entry['lastmod'] ?? null) ? $entry['lastmod'] : null;

            if (is_string($loc) && $loc !== '') {
                $files->add(self::normalize($loc), $lastmod);
            }
        }

        Cache::forever(self::MODELS_KEY, array_values(array_unique($models)));
        $pages = $files->finish();

        return count($pages) === 1
            ? ['main' => $pages[0], 'pages' => []]
            : ['main' => self::index(count($pages)), 'pages' => $pages];
    }

    /**
     * Индекс карт: ссылки на файлы `/sitemap-1.xml` … `/sitemap-N.xml`.
     */
    private static function index(int $files): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach (range(1, $files) as $number) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', SeoTags::absolute('/sitemap-'.$number.'.xml'));
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Запись скрыта от поисковиков полем Seo.
     */
    private static function isHidden(Model $record, ?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        $value = $record->getAttribute($field);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) && ($value['noindex'] ?? false) === true;
    }

    /**
     * Адрес на домене сайта: путь и параметры берутся из адреса записи, домен — из `APP_URL`.
     */
    private static function normalize(string $url): string
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '/') : $url;
        $query = is_array($parts) && isset($parts['query']) ? '?'.$parts['query'] : '';

        return SeoTags::absolute($path.$query);
    }
}
