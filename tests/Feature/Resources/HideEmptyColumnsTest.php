<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('HideEmpty');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Столбцы таблицы списка статей, как их нарисовала страница (по `data-field` ячеек).
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function listColumnLabels(array $query = []): array
{
    $content = test()->get(route('fastleo.resources.index', ['resource' => 'articles', ...$query]))->assertOk()->getContent();
    preg_match_all('/<td data-field="([^"]+)"/', (string) $content, $matches);

    return array_values(array_unique($matches[1]));
}

it('removes a column while every value on the page is empty', function (): void {
    Article::factory()->count(2)->create(['phone' => null, 'author_email' => null, 'cover' => null]);

    expect(listColumnLabels())->toBe(['title']);
});

it('brings the column back as soon as one record has a value', function (): void {
    Article::factory()->create(['phone' => null, 'author_email' => null, 'cover' => null]);
    Article::factory()->create(['phone' => '+7 900 000-00-00', 'author_email' => null, 'cover' => 'uploads/a.jpg']);

    expect(listColumnLabels())->toBe(['title', 'phone', 'cover']);
});

it('keeps the first column and the column of the current sort', function (): void {
    Article::factory()->create(['title' => '', 'phone' => null, 'author_email' => null, 'cover' => null]);

    expect(listColumnLabels(['sort' => 'author_email']))->toBe(['title', 'author_email']);
});

it('hides nothing on an empty page and removes the header of a hidden column', function (): void {
    expect(Fastleo::resources()->find('articles')?->listColumns([]))->toHaveCount(4);

    Article::factory()->create(['phone' => null, 'author_email' => null, 'cover' => null]);

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('>Телефон<', false)->assertDontSee('>Обложка<', false);
});

it('keeps an empty column that is edited in the cell', function (): void {
    useFixtureResources('HideEmptyInline');
    Article::factory()->create(['phone' => null]);

    expect(listColumnLabels())->toBe(['title', 'phone']);
});
