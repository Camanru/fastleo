<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Duplicates\ArticleResource;
use Fastleo\Tests\Fixtures\Policies\NoViewArticlePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Duplicates');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->existing = Article::factory()->create(['title' => 'Уникальная']);
});

afterEach(function (): void {
    ArticleResource::$scoped = false;
    ArticleResource::$foreign = false;
});

/**
 * Форма создания после отправки занятого заголовка.
 */
function takenTitleForm(): TestResponse
{
    return test()->from(route('fastleo.resources.create', 'duplicate-articles'))
        ->followingRedirects()
        ->post(route('fastleo.resources.store', 'duplicate-articles'), ['title' => 'Уникальная']);
}

it('shows a link to the record that already has the value', function (): void {
    takenTitleForm()
        ->assertOk()
        ->assertSee('Проверьте форму')
        ->assertSee('Открыть: Уникальная')
        ->assertSee(route('fastleo.resources.show', ['duplicate-articles', $this->existing->id]), false);
});

it('shows no link for a value that is free or for an error of another rule', function (): void {
    $this->from(route('fastleo.resources.create', 'duplicate-articles'))->followingRedirects()
        ->post(route('fastleo.resources.store', 'duplicate-articles'), ['title' => ''])
        ->assertOk()
        ->assertDontSee('Открыть:');
});

it('shows no link to a record that the admin may not open', function (): void {
    Gate::policy(Article::class, NoViewArticlePolicy::class);

    takenTitleForm()->assertOk()->assertSee('Проверьте форму')->assertDontSee('Открыть:');
});

it('shows neither the title nor a link of a record that lies outside query() of the section', function (): void {
    ArticleResource::$scoped = true;

    takenTitleForm()->assertOk()->assertSee('Проверьте форму')->assertDontSee('Открыть:')->assertDontSee(route('fastleo.resources.show', ['duplicate-articles', $this->existing->id]), false);

    Article::factory()->create(['title' => 'Моя статья']);
    $this->from(route('fastleo.resources.create', 'duplicate-articles'))->followingRedirects()
        ->post(route('fastleo.resources.store', 'duplicate-articles'), ['title' => 'Моя статья'])
        ->assertOk()
        ->assertSee('Открыть: Моя статья');
});

it('shows no link when the closure returns a record of another section', function (): void {
    ArticleResource::$foreign = true;
    $category = Category::factory()->create(['title' => 'Уникальная']);
    // У статьи тот же ключ, что у рубрики: проверка «есть в query() раздела» по одному ключу не должна принять рубрику за статью.
    if (! Article::query()->whereKey($category->getKey())->exists()) {
        Article::factory()->create(['id' => $category->getKey()]);
    }

    takenTitleForm()->assertOk()->assertSee('Проверьте форму')->assertDontSee('Открыть:');
});

it('finds the record only for a non-empty string', function (): void {
    $field = Fastleo::resources()->find('duplicate-articles')->schema()->field('title');

    expect($field->duplicateRecord('Уникальная')?->getKey())->toBe($this->existing->getKey())
        ->and($field->duplicateRecord(''))->toBeNull()
        ->and($field->duplicateRecord(['x']))->toBeNull();
});
