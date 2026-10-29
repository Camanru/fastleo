<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Fastleo\Tests\Fixtures\Policies\ImportingCategoryPolicy;
use Fastleo\Tests\Fixtures\Policies\RestrictedCategoryPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('allows everything when the model has no policy', function (): void {
    $category = Category::factory()->create();

    $this->get(route('fastleo.resources.create', 'categories'))->assertOk();
    $this->get(route('fastleo.resources.edit', ['categories', $category->id]))->assertOk();
});

it('forbids actions the policy denies with 403', function (string $method, string $route, bool $withRecord): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);
    $category = Category::factory()->create(['title' => 'Закрыто']);

    $response = $this->call($method, route($route, $withRecord ? ['categories', $category->id] : 'categories'), ['title' => 'Взлом']);

    $response->assertForbidden();
    expect($category->refresh()->title)->toBe('Закрыто')
        ->and(Category::query()->count())->toBe(1);
})->with([
    'create form' => ['GET', 'fastleo.resources.create', false],
    'store' => ['POST', 'fastleo.resources.store', false],
    'edit form' => ['GET', 'fastleo.resources.edit', true],
    'update' => ['PUT', 'fastleo.resources.update', true],
    'delete — no policy method' => ['DELETE', 'fastleo.resources.destroy', true],
]);

it('hides buttons the policy denies', function (): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);
    $closed = Category::factory()->create(['title' => 'Закрыто']);
    $open = Category::factory()->create(['title' => 'Открыто']);

    $this->get(route('fastleo.resources.index', 'categories'))
        ->assertOk()
        ->assertDontSee(route('fastleo.resources.create', 'categories'))
        ->assertDontSee(route('fastleo.resources.edit', ['categories', $closed->id]))
        ->assertSee(route('fastleo.resources.edit', ['categories', $open->id]))
        ->assertSee(route('fastleo.resources.show', ['categories', $closed->id]))
        ->assertDontSee('name="_method" value="DELETE"', false);
});

it('hides the section from the menu and forbids its list when viewAny is denied', function (): void {
    Gate::policy(Category::class, HiddenCategoryPolicy::class);

    $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee(route('fastleo.resources.index', 'categories'));
    $this->get(route('fastleo.resources.index', 'categories'))->assertForbidden();
});

it('asks the policy as the admin signed in to fastleo', function (): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);
    $category = Category::factory()->create(['title' => 'Открыто']);

    $this->put(route('fastleo.resources.update', ['categories', $category->id]), ['title' => 'Новое'])
        ->assertRedirect(route('fastleo.resources.edit', ['categories', $category->id]));
    expect($category->refresh()->title)->toBe('Новое');
});

it('lets the resource forbid an own ability of the policy through can()', function (): void {
    Gate::policy(Category::class, ImportingCategoryPolicy::class);
    $resource = new class extends Resource
    {
        protected static string $model = Category::class;

        public function fields(): array
        {
            return [];
        }

        public function can(string $ability, ?Model $record): bool
        {
            return $ability !== 'import';
        }
    };

    expect(Authorization::allowsOwnOr('import', 'viewAny', $resource))->toBeFalse()
        ->and(Authorization::allowsOwnOr('export', 'viewAny', $resource))->toBeTrue();
});

it('lets the resource forbid export, import and reorder through can() when the policy has no such methods', function (): void {
    $resource = new class extends Resource
    {
        protected static string $model = Category::class;

        public function fields(): array
        {
            return [];
        }

        public function can(string $ability, ?Model $record): bool
        {
            return ! in_array($ability, ['export', 'import', 'reorder'], true);
        }
    };
    $category = Category::factory()->create();

    expect(Authorization::allowsOwnOr('export', 'viewAny', $resource))->toBeFalse()
        ->and(Authorization::allowsOwnOr('import', 'viewAny', $resource))->toBeFalse()
        ->and(Authorization::allowsReorder($resource, $category))->toBeFalse()
        ->and(Authorization::allows('viewAny', $resource))->toBeTrue();
});

it('asks the policy a bounded number of times per row of the list', function (): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);
    Category::factory()->count(10)->create();
    $asked = 0;
    Gate::after(function () use (&$asked): void {
        $asked++;
    });

    $this->get(route('fastleo.resources.index', 'categories'))->assertOk();

    // Было около 14 проверок на строку: таблица, карточки для телефона и кнопки строки спрашивали Policy заново.
    expect($asked)->toBeLessThanOrEqual(10 * 8);
});
