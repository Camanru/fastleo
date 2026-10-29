<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;
use Workbench\App\Models\ArticleBanner;
use Workbench\App\Models\ArticleLink;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Ссылки статьи по порядку: [название, адрес, порядок].
 *
 * @return list<array{0: string, 1: string|null, 2: int}>
 */
function articleLinks(Article $article): array
{
    return $article->links()->orderBy('sort')->get()->map(fn (ArticleLink $link): array => [$link->title, $link->url, $link->sort])->all();
}

describe('has many', function (): void {
    it('renders saved blocks with their ids and a template for new ones', function (): void {
        $article = Article::factory()->create();
        $link = $article->links()->create(['title' => 'Прайс', 'url' => 'https://example.com']);

        $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
            ->assertOk()
            ->assertSee('<input type="hidden" name="links[0][id]" value="'.$link->id.'">', false)
            ->assertSee('name="links[0][title]"', false)
            ->assertSee('value="Прайс"', false)
            ->assertSee('name="links[__KEY__][title]"', false)
            ->assertSee('data-block-handle', false);
    });

    it('marks the start and the end of the form', function (): void {
        $this->get(route('fastleo.resources.create', 'articles'))
            ->assertSee('name="_fl_begin"', false)
            ->assertSee('name="_fl_end"', false);
    });

    it('saves nothing and keeps the blocks when the server received only the start of a long form', function (): void {
        $article = Article::factory()->create(['title' => 'Было']);
        $article->links()->create(['title' => 'Первая', 'sort' => 1]);
        $article->links()->create(['title' => 'Вторая', 'sort' => 2]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Стало', '_fl_begin' => '1', 'links' => [
            '0' => ['id' => (string) $article->links()->first()?->id, 'title' => 'Первая'],
        ]]))->assertRedirect(route('fastleo.resources.edit', ['articles', $article->id]))
            ->assertSessionHas('fastleo.error');

        expect(articleLinks($article))->toHaveCount(2)
            ->and($article->fresh()?->title)->toBe('Было');
    });

    it('saves a form whose start and end both arrived', function (): void {
        $article = Article::factory()->create(['title' => 'Было']);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Стало', '_fl_begin' => '1', '_fl_end' => '1']))
            ->assertSessionHasNoErrors();

        expect($article->fresh()?->title)->toBe('Стало');
    });

    it('updates, reorders, adds and deletes blocks', function (): void {
        $article = Article::factory()->create();
        $keep = $article->links()->create(['title' => 'Оставить', 'sort' => 1]);
        $drop = $article->links()->create(['title' => 'Удалить', 'sort' => 2]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => [
            'new-1' => ['title' => 'Новая'],
            '0' => ['id' => (string) $keep->id, 'title' => 'Оставить (изм.)', 'url' => ''],
        ]]))->assertSessionHasNoErrors();

        expect(articleLinks($article))->toBe([['Новая', null, 1], ['Оставить (изм.)', null, 2]])
            ->and(ArticleLink::query()->find($drop->id))->toBeNull()
            ->and($keep->fresh()?->id)->toBe($keep->id);
    });

    it('deletes all blocks by the hidden empty value and keeps them when the field is not sent', function (): void {
        $article = Article::factory()->create();
        $article->links()->create(['title' => 'Ссылка']);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData());
        expect($article->links()->count())->toBe(1);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => '']));
        expect($article->links()->count())->toBe(0);
    });

    it('does not bring deleted blocks back after a validation error', function (): void {
        $article = Article::factory()->create();
        $article->links()->create(['title' => 'Старая ссылка']);
        $edit = route('fastleo.resources.edit', ['articles', $article->id]);

        $this->from($edit)->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => '', 'links' => '']))
            ->assertSessionHasErrors('title');

        $this->get($edit)->assertOk()->assertDontSee('value="Старая ссылка"', false);
    });

    it('checks every block and shows the error under its field', function (): void {
        $response = $this->post(route('fastleo.resources.store', 'articles'), articleData(['links' => [
            'new-7' => ['title' => '', 'url' => 'не адрес'],
        ]]));

        $response->assertSessionHasErrors([
            'links.new-7.title' => 'Заполните поле «Название».',
            'links.new-7.url' => 'Поле «Адрес» должно быть адресом (https://...).',
        ]);
        expect(Article::query()->count())->toBe(0);

        $this->get(route('fastleo.resources.create', 'articles'))
            ->assertSee('name="links[new-7][url]"', false)
            ->assertSee('value="не адрес"', false)
            ->assertSee('Заполните поле «Название».');
    });

    it('requires blocks when a minimum is set', function (): void {
        useFixtureResources('Blocks');
        $article = Article::factory()->create();
        $article->links()->create(['title' => 'Ссылка']);

        $this->post(route('fastleo.resources.store', 'linked-articles'), ['title' => 'Статья', 'links' => ''])->assertSessionHasErrors('links');
        $this->put(route('fastleo.resources.update', ['linked-articles', $article->id]), ['title' => 'Статья', 'links' => ''])->assertSessionHasErrors('links');

        expect($article->links()->count())->toBe(1);
    });

    it('limits the number of blocks', function (): void {
        $links = array_fill(0, 11, ['title' => 'Ссылка']);

        $this->post(route('fastleo.resources.store', 'articles'), articleData(['links' => $links]))
            ->assertSessionHasErrors('links');
    });

    it('shows the blocks as a table on the record page', function (): void {
        $article = Article::factory()->create();
        $article->links()->create(['title' => 'Прайс', 'url' => 'https://example.com/price']);
        $article->banner()->create(['title' => 'Скидки', 'is_active' => true]);

        $this->get(route('fastleo.resources.show', ['articles', $article->id]))
            ->assertOk()
            ->assertSeeInOrder(['Полезные ссылки', 'Название', 'Адрес', 'Прайс', 'https://example.com/price'])
            ->assertSeeInOrder(['Баннер', 'Текст баннера', 'Скидки']);
    });
});

describe('has one', function (): void {
    it('creates the banner only when something is filled', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), articleData(['banner' => ['title' => '', 'link' => '', 'is_active' => '0']]));
        expect(ArticleBanner::query()->count())->toBe(0);

        $article = Article::query()->sole();
        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['banner' => ['title' => 'Скидки', 'link' => 'https://example.com', 'is_active' => '1']]))
            ->assertSessionHasNoErrors();

        $banner = $article->banner()->sole();
        expect([$banner->title, $banner->link, $banner->is_active])->toBe(['Скидки', 'https://example.com', true]);
    });

    it('does not create the banner from untouched defaults', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), articleData(['banner' => ['title' => '', 'link' => '', 'is_active' => '1']]))
            ->assertSessionHasNoErrors();

        expect(ArticleBanner::query()->count())->toBe(0);
    });

    it('updates the existing banner instead of adding another', function (): void {
        $article = Article::factory()->create();
        $article->banner()->create(['title' => 'Старый']);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['banner' => ['title' => 'Новый', 'link' => '', 'is_active' => '0']]))
            ->assertSessionHasNoErrors();

        expect(ArticleBanner::query()->pluck('title')->all())->toBe(['Новый']);
        $this->get(route('fastleo.resources.edit', ['articles', $article->id]))->assertSee('name="banner[title]"', false)->assertSee('value="Новый"', false);
    });
});
