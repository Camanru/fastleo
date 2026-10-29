<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('lists and shows records of a read-only section', function (): void {
    $feedback = Feedback::factory()->create(['name' => 'Иван', 'message' => 'Хочу записаться']);

    $this->get(route('fastleo.resources.index', 'feedback'))
        ->assertOk()
        ->assertSee('Иван')
        ->assertSee('Хочу записаться')
        ->assertDontSee(route('fastleo.resources.create', 'feedback'))
        ->assertDontSee(route('fastleo.resources.edit', ['feedback', $feedback->id]))
        ->assertSee(route('fastleo.resources.show', ['feedback', $feedback->id]));
    $this->get(route('fastleo.resources.show', ['feedback', $feedback->id]))->assertOk()->assertSee('Хочу записаться');
});

it('forbids changes in a read-only section', function (string $method, string $route, bool $withRecord): void {
    $feedback = Feedback::factory()->create(['name' => 'Иван']);

    $this->call($method, route($route, $withRecord ? ['feedback', $feedback->id] : 'feedback'), ['name' => 'Взлом', 'email' => 'x@example.com', 'message' => 'x'])
        ->assertForbidden();

    expect($feedback->refresh()->name)->toBe('Иван')
        ->and(Feedback::query()->count())->toBe(1);
})->with([
    'create form' => ['GET', 'fastleo.resources.create', false],
    'store' => ['POST', 'fastleo.resources.store', false],
    'edit form' => ['GET', 'fastleo.resources.edit', true],
    'update' => ['PUT', 'fastleo.resources.update', true],
    'delete' => ['DELETE', 'fastleo.resources.destroy', true],
]);

it('shows the badge in the menu', function (): void {
    Feedback::factory()->count(3)->create();
    Feedback::factory()->create(['created_at' => now()->subDays(2)]);

    $this->get(route('fastleo.dashboard'))->assertOk()->assertSeeInOrder(['Обращения', '3']);
});
