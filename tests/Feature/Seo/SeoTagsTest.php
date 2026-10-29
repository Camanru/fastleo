<?php

declare(strict_types=1);

use Fastleo\Files\FileStore;
use Fastleo\Support\SeoTags;
use Illuminate\Support\Facades\Blade;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    config(['app.url' => 'https://site.test', 'app.name' => 'Клиника']);
});

/**
 * Показать публичную страницу статьи с мета-тегами.
 */
function articlePage(mixed $test, Article $article, string $query = '', array $server = []): TestResponse
{
    return $test->call('GET', '/articles/'.$article->id.$query, server: $server);
}

describe('component', function (): void {
    it('prints the values of the Seo field and the Open Graph tags', function (): void {
        $article = Article::factory()->create(['title' => 'Название', 'seo' => ['title' => 'Лечение зубов', 'description' => 'Описание для поиска', 'image' => 'articles/a.jpg']]);

        articlePage($this, $article)
            ->assertOk()
            ->assertSee('<title>Лечение зубов</title>', false)
            ->assertSee('<meta name="description" content="Описание для поиска">', false)
            ->assertSee('<meta property="og:title" content="Лечение зубов">', false)
            ->assertSee('<meta property="og:description" content="Описание для поиска">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:site_name" content="Клиника">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<link rel="canonical" href="https://site.test/articles/'.$article->id.'">', false)
            ->assertSee('property="og:image" content="'.SeoTags::absolute(FileStore::url('articles/a.jpg')).'"', false)
            ->assertDontSee('noindex');
    });

    it('falls back from the field to the page values and then to the site name', function (): void {
        $article = Article::factory()->create(['title' => 'Название статьи', 'content' => '<h2>Заголовок</h2><p>Первый абзац.</p>', 'seo' => null]);

        articlePage($this, $article)
            ->assertSee('<title>Название статьи</title>', false)
            ->assertSee('<meta name="description" content="Первый абзац.">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertDontSee('og:image');

        expect(SeoTags::resolve()['title'])->toBe('Клиника');
    });

    it('asks search engines to skip the page marked noindex', function (): void {
        $article = Article::factory()->create(['seo' => ['noindex' => true]]);

        articlePage($this, $article)->assertSee('<meta name="robots" content="noindex">', false);
    });

    it('builds the canonical address from APP_URL without the query and the Host header', function (): void {
        $article = Article::factory()->create();

        articlePage($this, $article, '?utm_source=mail&page=2', ['HTTP_HOST' => 'evil.example'])
            ->assertSee('<link rel="canonical" href="https://site.test/articles/'.$article->id.'">', false)
            ->assertDontSee('evil.example');
    });

    it('escapes the values', function (): void {
        $article = Article::factory()->create(['seo' => ['title' => '"><script>alert(1)</script>', 'description' => '" onload="x']]);

        articlePage($this, $article)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    it('takes attributes of the tag when there is no model', function (): void {
        $html = Blade::render('<x-fastleo::seo title="Контакты" description="<p>Мы работаем <b>ежедневно</b></p>" canonical="/contacts" type="website" />');

        expect($html)->toContain('<title>Контакты</title>')
            ->and($html)->toContain('<meta name="description" content="Мы работаем ежедневно">')
            ->and($html)->toContain('<link rel="canonical" href="https://site.test/contacts">');
    });
});

describe('values', function (): void {
    it('cuts the description taken from HTML at a word, without headings, scripts and tags', function (): void {
        $html = '<h1>Заголовок</h1><script>alert(1)</script><p>'.str_repeat('слово ', 60).'&laquo;конец&raquo;&nbsp;</p>';
        $description = SeoTags::excerpt($html);

        expect(mb_strlen((string) $description))->toBeLessThanOrEqual(160)
            ->and($description)->toEndWith('…')
            ->and($description)->not->toContain('Заголовок')
            ->and($description)->not->toContain('alert')
            ->and(SeoTags::excerpt('<p> </p>'))->toBeNull()
            ->and(SeoTags::excerpt(null))->toBeNull()
            ->and(SeoTags::excerpt('<p>Коротко &laquo;да&raquo;</p>'))->toBe('Коротко «да»');
    });

    it('accepts only safe image addresses', function (?string $image, ?string $expected): void {
        expect(SeoTags::resolve(image: $image)['image'])->toBe($expected === 'disk' ? SeoTags::absolute(FileStore::url((string) $image)) : $expected);
    })->with([
        'https' => ['https://cdn.test/a.jpg', 'https://cdn.test/a.jpg'],
        'path on the disk' => ['articles/a.jpg', 'disk'],
        'javascript' => ['javascript:alert(1)', null],
        'data' => ['data:image/svg+xml;base64,AAAA', null],
        'protocol-relative' => ['//evil.test/a.jpg', null],
        'empty' => ['', null],
        'null' => [null, null],
    ]);

    it('falls back to the website type for a strange one', function (): void {
        expect(SeoTags::resolve(type: '"><x')['type'])->toBe('website')
            ->and(SeoTags::resolve(type: 'article')['type'])->toBe('article')
            ->and(SeoTags::resolve(type: 'music.song')['type'])->toBe('music.song');
    });

    it('reads the field from a JSON string and ignores a foreign value', function (): void {
        $article = new Article;
        $article->setRawAttributes(['seo' => '{"title":"Из строки"}']);

        expect(SeoTags::resolve($article)['title'])->toBe('Из строки');

        $article->setRawAttributes(['seo' => 'не json']);

        expect(SeoTags::resolve($article)['title'])->toBe('Клиника');
    });
});
