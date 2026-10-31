<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Related\ArticleResource;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Related');
    $this->actingAs(admin(), Fastleo::GUARD);
    Article::factory()->count(30)->create(['category_id' => Category::factory()->create()->id]);
});

/**
 * Сколько строк записей в таблице страницы (карточки для телефона не считаются).
 */
function listedRows(string $html): int
{
    return substr_count($html, 'data-parent="');
}

describe('the sizes', function (): void {
    it('are the configured ones plus the usual size of the section, in ascending order', function (): void {
        config(['fastleo.list.per_page' => 25, 'fastleo.list.per_page_options' => [100, 50, '10', 0, -5, 'abc', 5000]]);

        expect(ArticleResource::perPageOptions())->toBe([10, 25, 50, 100]);
    });

    it('are empty when nothing is configured', function (): void {
        config(['fastleo.list.per_page_options' => []]);

        expect(ArticleResource::perPageOptions())->toBe([]);
    });
});

describe('the list', function (): void {
    it('shows the usual number of records and the choice of the size under the table', function (): void {
        $html = $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('data-per-page', false)->assertSee('На странице')->getContent();

        expect(listedRows($html))->toBe(25);
    });

    it('takes the chosen size from the address and marks it in the choice', function (): void {
        $html = $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'per_page' => 50]))->assertOk()->getContent();

        expect(listedRows($html))->toBe(30)
            ->and($html)->toMatch('/<option value="[^"]*per_page=50[^"]*" selected>50<\/option>/');
    });

    it('ignores a size that is not offered and a value that is not a number', function (string $size): void {
        $html = $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'per_page' => $size]))->assertOk()->getContent();

        expect(listedRows($html))->toBe(25);
    })->with(['not offered' => '7', 'too big' => '5000', 'text' => 'abc', 'negative' => '-50', 'with a tail' => '50abc']);

    it('keeps the chosen size when the list is searched or filtered', function (): void {
        $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'per_page' => 50]))
            ->assertOk()
            ->assertSee('<input type="hidden" name="per_page" value="50">', false);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('name="per_page"', false);
    });

    it('does not break the lists when the options in the config were turned into null', function (): void {
        config(['fastleo.list.per_page_options' => null]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('data-per-page', false);
    });

    it('keeps the button "All" working next to the choice', function (): void {
        $html = $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'per_page' => 'all']))->assertOk()->getContent();

        expect(listedRows($html))->toBe(30)->and($html)->not->toMatch('/<option [^>]*selected>/');
    });

    it('has no choice when the options are turned off or the records fit on one page', function (): void {
        config(['fastleo.list.per_page_options' => []]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('data-per-page', false);

        config(['fastleo.list.per_page_options' => [25, 50]]);
        Article::query()->limit(10)->get()->each->delete();
        Article::query()->latest('id')->limit(10)->get()->each->delete();

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('data-per-page', false);
    });
});
