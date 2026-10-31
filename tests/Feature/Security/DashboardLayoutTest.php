<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Widgets\DashboardLayout;

beforeEach(function (): void {
    useFixtureResources('DashboardLayout');
});

it('rejects saving and resetting the layout without a csrf token with 419', function (): void {
    $this->app['env'] = 'production';
    $this->actingAs(admin(['name' => 'Анна']), Fastleo::GUARD);

    $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => []])->assertStatus(419);
    $this->delete(route('fastleo.dashboard.layout.reset'))->assertStatus(419);

    expect(DashboardLayout::query()->count())->toBe(0);
});

it('does not save a widget that is closed to this administrator', function (): void {
    $this->actingAs(admin(['name' => 'Борис']), Fastleo::GUARD);

    $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => [
        ['key' => 'stat-tolko-anne', 'cols' => 4, 'rows' => 4, 'hidden' => false],
        ['key' => 'counter', 'cols' => 2, 'rows' => 1, 'hidden' => false],
        ['key' => 'counter', 'cols' => 4, 'rows' => 4, 'hidden' => true],
        ['key' => 'unknown-widget', 'cols' => 1, 'rows' => 1, 'hidden' => false],
    ]])->assertNoContent();

    expect(DashboardLayout::query()->first()?->layout)->toBe([['key' => 'counter', 'cols' => 2, 'rows' => 1, 'hidden' => false]]);
});

it('accepts the sizes and the flag sent as text, the way a form or a hand-made request sends them', function (): void {
    $this->actingAs(admin(['name' => 'Борис']), Fastleo::GUARD);

    $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => [
        ['key' => 'counter', 'cols' => '2', 'rows' => '3', 'hidden' => '1'],
    ]])->assertNoContent();

    expect(DashboardLayout::query()->first()?->layout)->toBe([['key' => 'counter', 'cols' => 2, 'rows' => 3, 'hidden' => true]]);
});

it('writes the layout only for the signed-in administrator', function (): void {
    $victim = admin(['name' => 'Анна']);
    $me = admin(['name' => 'Борис']);
    $this->actingAs($me, Fastleo::GUARD);

    $this->putJson(route('fastleo.dashboard.layout.save'), [
        'user_id' => (string) $victim->getKey(),
        'widgets' => [['key' => 'counter', 'cols' => 1, 'rows' => 1, 'hidden' => true]],
    ])->assertNoContent();

    expect(DashboardLayout::query()->where('user_id', (string) $victim->getKey())->exists())->toBeFalse()
        ->and(DashboardLayout::query()->where('user_id', (string) $me->getKey())->exists())->toBeTrue();
});

it('does not reset the layout of another administrator', function (): void {
    $other = admin();
    DashboardLayout::query()->create(['user_id' => (string) $other->getKey(), 'layout' => []]);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->delete(route('fastleo.dashboard.layout.reset'))->assertRedirect(route('fastleo.dashboard'));

    expect(DashboardLayout::query()->where('user_id', (string) $other->getKey())->exists())->toBeTrue();
});

it('does not let a non-administrator touch the layout', function (): void {
    $this->putJson(route('fastleo.dashboard.layout.save'), ['widgets' => []])->assertUnauthorized();
    $this->delete(route('fastleo.dashboard.layout.reset'))->assertRedirect();

    expect(DashboardLayout::query()->count())->toBe(0);
});
