<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\ModelDefaults;
use Fastleo\Tests\Fixtures\Policies\NoCreateArticlePolicy;
use Fastleo\Tests\Fixtures\Prefill\ArticleResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Prefill');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Страница создания записи с предзаполнением из адреса.
 *
 * @param  array<string, mixed>  $prefill
 */
function createWith(array $prefill): TestResponse
{
    return test()->get(route('fastleo.resources.create', ['resource' => 'prefill', 'prefill' => $prefill]));
}

describe('simple fields', function (): void {
    it('fills text, number, date and list fields from the address', function (): void {
        createWith(['title' => 'Из ссылки', 'views' => '15', 'published_on' => '2026-03-08', 'status' => 'review'])
            ->assertOk()
            ->assertSee('value="Из ссылки"', false)
            ->assertSee('value="15"', false)
            ->assertSee('value="2026-03-08"', false)
            ->assertSee('<option value="review" selected>', false);
    });

    it('shows a related record chosen by id as the selected value', function (): void {
        $category = Category::factory()->create(['title' => 'Вентиляция', 'code' => 'vent']);

        createWith(['category_id' => (string) $category->id])
            ->assertOk()
            ->assertSee('Вентиляция');
    });

    it('lets the old input of a failed form win over the address', function (): void {
        $this->withSession(['_old_input' => ['title' => 'Введено руками']])
            ->get(route('fastleo.resources.create', ['resource' => 'prefill', 'prefill' => ['title' => 'Из ссылки']]))
            ->assertOk()
            ->assertSee('value="Введено руками"', false)
            ->assertDontSee('value="Из ссылки"', false);
    });
});

describe('values that are skipped without an error', function (): void {
    it('skips a value the form would reject', function (): void {
        createWith(['title' => str_repeat('я', 21), 'status' => 'unknown', 'views' => 'abc', 'published_on' => 'вчера'])
            ->assertOk()
            ->assertDontSee('unknown')
            ->assertDontSee('value="abc"', false)
            ->assertDontSee('value="вчера"', false)
            ->assertDontSee(str_repeat('я', 21));
    });

    it('skips a related record outside the query() of the field', function (): void {
        $hidden = Category::factory()->create(['title' => 'Служебная', 'code' => null]);

        createWith(['category_id' => (string) $hidden->id])
            ->assertOk()
            ->assertDontSee('Служебная');
    });

    it('skips fields that are not on the form, read-only, passwords, checkboxes, tags and hidden fields', function (): void {
        createWith(['author_email' => 'prefill-author@site.test', 'color' => 'секрет', 'is_featured' => '1', 'keywords' => 'акция', 'slug' => 'x', 'id' => '5', 'type' => 'page'])
            ->assertOk()
            ->assertDontSee('value="page"', false)
            ->assertSee('value="news"', false)
            ->assertDontSee('prefill-author@site.test')
            ->assertDontSee('секрет')
            ->assertDontSee('value="акция"', false);
    });

    it('skips arrays and empty values', function (): void {
        createWith(['title' => ['a'], 'views' => ''])
            ->assertOk()
            ->assertDontSee('value="a"', false);
    });

    it('ignores a prefill that is not an array', function (): void {
        $this->get(route('fastleo.resources.create', ['resource' => 'prefill', 'prefill' => 'title']))->assertOk();
    });
});

describe('the values taken', function (): void {
    it('keeps only strings that would pass the form validation', function (): void {
        $open = Category::factory()->create(['code' => 'open']);
        $hidden = Category::factory()->create(['code' => null]);

        $values = ModelDefaults::prefill(new ArticleResource, [
            'title' => 'Заголовок',
            'status' => 'draft',
            'views' => '7',
            'category_id' => (string) $open->id,
            'author_email' => 'prefill-author@site.test',
            'color' => 'секрет',
            'is_featured' => '1',
            'keywords' => 'акция',
            'slug' => 'x',
        ]);

        expect($values)->toBe(['title' => 'Заголовок', 'status' => 'draft', 'views' => '7', 'category_id' => (string) $open->id])
            ->and(ModelDefaults::prefill(new ArticleResource, ['category_id' => (string) $hidden->id, 'status' => 'zzz', 'title' => ['a']]))->toBe([])
            ->and(ModelDefaults::prefill(new ArticleResource, 'title'))->toBe([]);
    });
});

describe('rights', function (): void {
    it('does not open the form without the create right', function (): void {
        Gate::policy(Article::class, NoCreateArticlePolicy::class);

        createWith(['title' => 'Из ссылки'])->assertForbidden();
    });
});
