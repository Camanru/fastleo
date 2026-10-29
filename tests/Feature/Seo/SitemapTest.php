<?php

declare(strict_types=1);

use Fastleo\Support\Sitemap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    Cache::flush();
    config(['app.url' => 'https://site.test']);
});

/**
 * Включить карту сайта: маршрут подключается провайдером при старте только по настройке, поэтому в тесте — вручную.
 *
 * @param  array<string, mixed>  $settings
 */
function enableSitemap(array $settings = []): void
{
    config(['fastleo.sitemap' => [...config('fastleo.sitemap'), 'enabled' => true, ...$settings]]);
    require __DIR__.'/../../../routes/sitemap.php';
    Route::getRoutes()->refreshNameLookups();
}

/**
 * Адреса из карты сайта.
 *
 * @return list<string>
 */
function sitemapLocs(mixed $test): array
{
    $xml = $test->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    preg_match_all('#<loc>(.*?)</loc>#', (string) $xml, $matches);

    return array_map(html_entity_decode(...), $matches[1]);
}

describe('availability', function (): void {
    it('has no address until it is switched on', function (): void {
        $this->get('/sitemap.xml')->assertNotFound();
        expect(Route::has('fastleo.sitemap'))->toBeFalse();
    });

    it('is open without signing in', function (): void {
        enableSitemap();

        $this->get('/sitemap.xml')->assertOk()->assertSee('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', false);
    });
});

describe('records', function (): void {
    it('lists only published, indexed and existing articles of the sections that described sitemap()', function (): void {
        enableSitemap();
        $shown = Article::factory()->create(['is_published' => true, 'seo' => ['title' => 'Да']]);
        Article::factory()->create(['is_published' => false]);
        Article::factory()->create(['is_published' => true, 'seo' => ['noindex' => true]]);
        Article::factory()->create(['is_published' => true])->delete();
        Category::factory()->create();

        expect(sitemapLocs($this))->toBe(['https://site.test/articles/'.$shown->id]);
    });

    it('adds the date of the last change', function (): void {
        enableSitemap();
        $article = Article::factory()->create(['is_published' => true]);
        $article->forceFill(['updated_at' => '2026-03-08 10:00:00'])->saveQuietly();

        $this->get('/sitemap.xml')->assertSee('<lastmod>2026-03-08T10:00:00+00:00</lastmod>', false);
    });

    it('writes the address on the site domain even when the request came to another host', function (): void {
        enableSitemap();
        $article = Article::factory()->create(['is_published' => true]);

        $xml = $this->call('GET', '/sitemap.xml', server: ['HTTP_HOST' => 'evil.example'])->assertOk()->getContent();

        expect($xml)->toContain('<loc>https://site.test/articles/'.$article->id.'</loc>')
            ->and($xml)->not->toContain('evil.example');
    });

    it('lists every record when the section orders the sitemap by its own column', function (): void {
        useFixtureResources('SitemapOrder');
        enableSitemap();
        $created = Article::factory()->count(520)->sequence(fn ($sequence): array => ['created_at' => now()->subSeconds($sequence->index)])->create(['is_published' => true]);

        expect(sitemapLocs($this))->toHaveCount(520)->toContain('https://site.test/articles/'.$created->last()->id);
    });

    it('keeps one file while the addresses fit and has no numbered files', function (): void {
        enableSitemap(['per_file' => 3, 'urls' => ['/a', '/b', '/c']]);

        expect(sitemapLocs($this))->toHaveCount(3)
            ->and($this->get('/sitemap.xml')->getContent())->toContain('<urlset')->not->toContain('<sitemapindex');
        $this->get('/sitemap-1.xml')->assertNotFound();
    });

    it('becomes an index of numbered files when the addresses do not fit in one', function (): void {
        enableSitemap(['per_file' => 2, 'urls' => ['/a', '/b', '/c', '/d', '/e']]);

        $index = (string) $this->get('/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>(.*?)</loc>#', $index, $files);

        expect($index)->toContain('<sitemapindex')
            ->and($files[1])->toBe(['https://site.test/sitemap-1.xml', 'https://site.test/sitemap-2.xml', 'https://site.test/sitemap-3.xml']);

        $locs = [];

        foreach ([1, 2, 3] as $page) {
            $xml = (string) $this->get('/sitemap-'.$page.'.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
            preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches);
            $locs[] = $matches[1];
        }

        expect($locs)->toBe([
            ['https://site.test/a', 'https://site.test/b'],
            ['https://site.test/c', 'https://site.test/d'],
            ['https://site.test/e'],
        ]);
        $this->get('/sitemap-4.xml')->assertNotFound();
        $this->get('/sitemap-0.xml')->assertNotFound();
    });

    it('builds no more than ten files and never more than fifty thousand addresses in one', function (): void {
        enableSitemap(['per_file' => 1, 'urls' => array_map(fn (int $number): string => '/page-'.$number, range(1, 15))]);

        $index = (string) $this->get('/sitemap.xml')->getContent();

        expect(substr_count($index, '<sitemap>'))->toBe(Sitemap::MAX_FILES)
            ->and(Sitemap::LIMIT)->toBe(50000);
        $this->get('/sitemap-11.xml')->assertNotFound();

        config(['fastleo.sitemap.per_file' => 1000000]);
        Cache::flush();

        expect(sitemapLocs($this))->toHaveCount(15);
    });

    it('gives a valid empty sitemap when there is nothing to list', function (): void {
        enableSitemap();

        expect($this->get('/sitemap.xml')->assertOk()->getContent())->toContain('<urlset')->not->toContain('<url>');
    });
});

describe('own addresses', function (): void {
    it('adds the addresses from the config once, escaped and on the site domain', function (): void {
        enableSitemap(['urls' => ['/contacts', ['loc' => '/about?a=1&b=2', 'lastmod' => '2026-01-31'], '/contacts', 'https://other.test/page']]);

        $xml = $this->get('/sitemap.xml')->getContent();

        expect(sitemapLocs($this))->toBe(['https://site.test/contacts', 'https://site.test/about?a=1&b=2', 'https://site.test/page'])
            ->and($xml)->toContain('/about?a=1&amp;b=2')
            ->and($xml)->toContain('<lastmod>2026-01-31</lastmod>');
    });
});

describe('cache', function (): void {
    it('keeps the map until a record changes through Eloquent', function (): void {
        enableSitemap();
        $first = Article::factory()->create(['is_published' => true]);
        expect(sitemapLocs($this))->toBe(['https://site.test/articles/'.$first->id]);

        // Запись в обход Eloquent событие не вызывает — карта прежняя.
        DB::table('articles')->where('id', $first->id)->update(['is_published' => false]);
        expect(sitemapLocs($this))->toBe(['https://site.test/articles/'.$first->id]);

        $second = Article::factory()->create(['is_published' => true]);
        expect(sitemapLocs($this))->toBe(['https://site.test/articles/'.$second->id]);

        $second->delete();
        expect(sitemapLocs($this))->toBe([]);

        $second->restore();
        expect(sitemapLocs($this))->toBe(['https://site.test/articles/'.$second->id]);
    });

    it('is built on every request when the cache time is zero', function (): void {
        enableSitemap(['cache_minutes' => 0]);
        $article = Article::factory()->create(['is_published' => true]);
        sitemapLocs($this);

        DB::table('articles')->where('id', $article->id)->update(['is_published' => false]);

        expect(sitemapLocs($this))->toBe([]);
    });

    it('does not reset the map for a model that is not in it', function (): void {
        enableSitemap();
        $article = Article::factory()->create(['is_published' => true]);
        sitemapLocs($this);
        DB::table('articles')->where('id', $article->id)->update(['is_published' => false]);

        Category::factory()->create();

        expect(sitemapLocs($this))->toBe(['https://site.test/articles/'.$article->id]);
    });

    it('keeps every file of a large map under its own key so that no cache value grows with the whole map', function (): void {
        enableSitemap(['per_file' => 2, 'urls' => ['/a', '/b', '/c', '/d', '/e']]);

        $this->get('/sitemap.xml')->assertOk();
        $meta = Cache::get('fastleo.sitemap');

        expect($meta['files'])->toBe(3)
            ->and($meta['main'])->toContain('<sitemapindex')->not->toContain('<urlset')
            ->and(array_keys($meta))->toBe(['main', 'files']);

        // Файл берётся из своего ключа, без пересборки.
        Cache::put('fastleo.sitemap.page.2', '<cached-page-2/>', now()->addHour());
        expect($this->get('/sitemap-2.xml')->getContent())->toBe('<cached-page-2/>');
        $this->get('/sitemap-4.xml')->assertNotFound();
    });

    it('rebuilds the map when one of its files fell out of the cache', function (): void {
        enableSitemap(['per_file' => 2, 'urls' => ['/a', '/b', '/c']]);
        $this->get('/sitemap.xml')->assertOk();
        Cache::forget('fastleo.sitemap.page.2');

        $this->get('/sitemap-2.xml')->assertOk()->assertSee('https://site.test/c');
        expect(Cache::has('fastleo.sitemap.page.2'))->toBeTrue();
    });

    it('builds the map when only a numbered file is requested first', function (): void {
        enableSitemap(['per_file' => 1, 'urls' => ['/a', '/b']]);

        $this->get('/sitemap-2.xml')->assertOk()->assertSee('https://site.test/b');
        $this->get('/sitemap-3.xml')->assertNotFound();
    });
});
