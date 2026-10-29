<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Text;
use Fastleo\Support\FormSchema;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('form', function (): void {
    it('shows the block with the preview and the saved values', function (): void {
        $article = Article::factory()->create(['seo' => ['title' => 'Заголовок для поиска', 'description' => 'Описание', 'noindex' => true]]);

        $this->get(route('fastleo.resources.create', 'articles'))
            ->assertOk()
            ->assertSee('data-seo="seo"', false)
            ->assertSee('name="seo[title]"', false)
            ->assertSee('name="seo[description]"', false)
            ->assertSee('name="seo[image]"', false)
            ->assertSee('name="seo[noindex]"', false);

        $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
            ->assertOk()
            ->assertSee('Заголовок для поиска')
            ->assertSee('Описание');
    });

    it('is shown on the view page without raw JSON', function (): void {
        $article = Article::factory()->create(['seo' => ['title' => 'Заголовок <b>для</b> поиска', 'noindex' => true]]);

        $this->get(route('fastleo.resources.show', ['articles', $article->id]))
            ->assertOk()
            ->assertSee('Заголовок &lt;b&gt;для&lt;/b&gt; поиска', false)
            ->assertSee('Скрыто от поисковиков');
    });
});

describe('saving', function (): void {
    it('stores only the filled described values as one object', function (): void {
        $article = Article::factory()->create();

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData([
            'seo' => ['title' => '  Лечение  ', 'description' => '', 'noindex' => '1', 'evil' => 'x'],
        ]))->assertRedirect();

        expect($article->refresh()->seo)->toBe(['title' => 'Лечение', 'noindex' => true]);
    });

    it('clears the object when everything was emptied', function (): void {
        $article = Article::factory()->create(['seo' => ['title' => 'Было']]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData([
            'seo' => ['title' => '', 'description' => '', 'noindex' => '0'],
        ]))->assertRedirect();

        expect($article->refresh()->seo)->toBe([]);
    });

    it('rejects too long values and a foreign type', function (array $seo, string $error): void {
        $article = Article::factory()->create();

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['seo' => $seo]))
            ->assertSessionHasErrors($error);

        expect($article->refresh()->seo)->toBeNull();
    })->with([
        'title' => [['title' => str_repeat('а', 256)], 'seo.title'],
        'description' => [['description' => str_repeat('а', 501)], 'seo.description'],
        'array title' => [['title' => ['a']], 'seo.title'],
    ]);

    it('does not accept a path to a file outside the uploads as the image', function (): void {
        $article = Article::factory()->create();

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['seo' => ['image' => '../../.env']]))
            ->assertSessionHasErrors('seo.image');
    });
});

describe('description errors', function (): void {
    it('stops with a clear message when the model column has no array cast', function (): void {
        expect(fn () => Seo::make('meta')->fill(new Category, []))->toThrow(LogicException::class, 'meta');
    });

    it('cannot be used inside a repeater', function (): void {
        $repeater = Repeater::make('items')->fields([Seo::make('seo'), Text::make('title')]);

        expect(fn (): FormSchema => $repeater->schema())->toThrow(LogicException::class, 'seo');
    });

    it('has the label SEO unless another one is set', function (): void {
        expect(Seo::make()->getLabel())->toBe('SEO')
            ->and(Seo::make('seo', 'Для поисковиков')->getLabel())->toBe('Для поисковиков')
            ->and(Seo::make('seo')->name)->toBe('seo');
    });
});
