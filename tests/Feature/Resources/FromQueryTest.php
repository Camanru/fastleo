<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('FromQuery');
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->busy = Category::factory()->create(['title' => 'Загруженная']);
    $this->quiet = Category::factory()->create(['title' => 'Тихая']);
    Article::factory()->count(3)->create(['category_id' => $this->busy->id, 'views' => 1500]);
    Article::factory()->create(['category_id' => $this->quiet->id, 'views' => 5]);
});

describe('showing', function (): void {
    it('shows the values of the query in the list and the view page', function (): void {
        $this->get(route('fastleo.resources.index', 'categories'))
            ->assertOk()
            ->assertSee('Статей')
            ->assertSee('4 500 пр.')
            ->assertSee('5 пр.');

        $this->get(route('fastleo.resources.show', ['categories', $this->busy->id]))->assertOk()->assertSee('4 500 пр.');
    });

    it('sorts the list by the computed column in both directions', function (): void {
        $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'sort' => 'articles_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Загруженная', 'Тихая']);

        $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'sort' => 'views_total', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['Тихая', 'Загруженная']);
    });

    it('puts the computed column into the export', function (): void {
        $rows = exportedRows($this->get(route('fastleo.resources.export', ['resource' => 'categories', 'format' => 'csv'])), 'csv');
        $busy = collect($rows)->first(fn (array $row): bool => in_array('Загруженная', $row, true));

        expect($rows[0])->toContain('Статей', 'Просмотров')
            ->and($busy)->toContain('3');
    });
});

describe('writing', function (): void {
    it('has no input for the computed column in the form', function (): void {
        $this->get(route('fastleo.resources.edit', ['categories', $this->busy->id]))
            ->assertOk()
            ->assertDontSee('name="articles_count"', false)
            ->assertDontSee('name="views_total"', false);
    });

    it('ignores a posted value of the computed column', function (): void {
        $this->put(route('fastleo.resources.update', ['categories', $this->busy->id]), ['title' => 'Новое имя', 'articles_count' => '99'])
            ->assertSessionHasNoErrors();

        expect($this->busy->refresh()->title)->toBe('Новое имя')
            ->and(Category::query()->toBase()->where('id', $this->busy->id)->exists())->toBeTrue();
    });

    it('keeps the computed column out of the activity log and the snapshot of a deleted record', function (): void {
        $this->put(route('fastleo.resources.update', ['categories', $this->busy->id]), ['title' => 'Новое имя'])->assertSessionHasNoErrors();
        $update = Activity::query()->where('event', 'updated')->latest('id')->first();

        $this->delete(route('fastleo.resources.destroy', ['categories', $this->quiet->id]));
        $delete = Activity::query()->where('event', 'deleted')->latest('id')->first();

        expect(array_keys($update->changes))->toBe(['title'])
            ->and($delete->snapshot)->toHaveKey('title')
            ->and($delete->snapshot)->not->toHaveKey('articles_count')
            ->and($delete->snapshot)->not->toHaveKey('views_total');
    });
});

describe('description', function (): void {
    it('refuses a field that cannot be computed', function (): void {
        BelongsTo::make('category_id')->fromQuery();
    })->throws(LogicException::class, 'fromQuery()');

    it('refuses to search by a computed column in either order', function (): void {
        expect(fn (): Number => Number::make('n')->fromQuery()->searchable())->toThrow(LogicException::class, 'нельзя искать')
            ->and(fn (): Text => Text::make('t')->searchable()->fromQuery())->toThrow(LogicException::class, 'нельзя искать');
    });

    it('can be turned off again', function (): void {
        $field = Number::make('n')->fromQuery()->fromQuery(false);

        expect($field->isFromQuery())->toBeFalse()->and($field->isWritable())->toBeTrue()->and($field->writesAttribute())->toBeTrue();
    });
});
