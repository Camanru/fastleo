<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\RowTone\ArticleResource;
use Illuminate\View\ViewException;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('RowTone');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create();
    Article::factory()->create(['title' => 'Опубликованная', 'status' => 'published', 'category_id' => $this->category->id]);
    Article::factory()->create(['title' => 'На проверке', 'status' => 'review', 'category_id' => $this->category->id]);
    Article::factory()->create(['title' => 'Черновик', 'status' => 'draft', 'category_id' => $this->category->id]);
});

afterEach(function (): void {
    ArticleResource::$forced = null;
});

describe('the list', function (): void {
    it('marks the rows with the tone that the section chose and leaves the others plain', function (): void {
        $html = $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->getContent();

        expect(preg_match_all('/<tr\s+data-tone="success"/', $html))->toBe(1)
            ->and(preg_match_all('/<tr\s+data-tone="warning"/', $html))->toBe(1)
            ->and(preg_match_all('/<tr\s+data-tone=/', $html))->toBe(2);
    });

    it('marks the cards of the phone view the same way', function (): void {
        $html = $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->getContent();

        expect(preg_match_all('/<li\s+data-tone="success"/', $html))->toBe(1)
            ->and(preg_match_all('/<li\s+data-tone="warning"/', $html))->toBe(1)
            ->and(preg_match_all('/<li\s+data-tone=/', $html))->toBe(2);
    });

    it('reports a tone that does not exist instead of ignoring it', function (): void {
        ArticleResource::$forced = 'green';

        $this->withoutExceptionHandling()->get(route('fastleo.resources.index', 'articles'));
    })->throws(ViewException::class, 'green');
});

describe('the related table', function (): void {
    it('marks the rows the same way', function (): void {
        $html = $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))->assertOk()->getContent();

        expect(substr_count($html, 'data-tone="success"'))->toBe(1)
            ->and(substr_count($html, 'data-tone="warning"'))->toBe(1)
            ->and(substr_count($html, 'data-tone='))->toBe(2);
    });
});

it('has no tone by default', function (): void {
    useFixtureResources('Related');

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('data-tone', false);
});
