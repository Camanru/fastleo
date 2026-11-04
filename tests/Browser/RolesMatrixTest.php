<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    useRoles();
    Fastleo::resources()->flush();
    roleAdmin(role('fastleo-owner'), ['email' => 'admin@example.com']);
});

it('ticks view together with any other right and clears the row when view is unticked', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'roles'));

    $page->click('[data-permission="fastleo.articles.update"]')
        ->assertChecked('[data-permission="fastleo.articles.view"]')
        ->assertChecked('[data-permission="fastleo.articles.update"]')
        ->click('[data-permission="fastleo.articles.view"]')
        ->assertNotChecked('[data-permission="fastleo.articles.view"]')
        ->assertNotChecked('[data-permission="fastleo.articles.update"]')
        ->assertNoJavaScriptErrors();
});

it('ticks a whole column and a whole row with one click', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'roles'));

    $page->click('thead button[title*="Просмотр"]')
        ->assertChecked('[data-permission="fastleo.articles.view"]')
        ->assertChecked('[data-permission="fastleo.categories.view"]')
        ->click('tbody tr:first-child th button')
        ->assertChecked('tbody tr:first-child input[data-permission$=".delete"]')
        ->click('thead button[title*="Просмотр"]')
        ->assertNotChecked('[data-permission="fastleo.categories.view"]')
        ->assertNoJavaScriptErrors();
});

it('saves the role with the ticked rights', function (): void {
    $page = signInAndVisit(route('fastleo.resources.create', 'roles'));

    $page->fill('name', 'Редактор статей')
        ->click('[data-permission="fastleo.articles.update"]')
        ->click('[data-permission="fastleo.categories.view"]')
        ->press('Сохранить')
        ->waitForEvent('networkidle')
        ->assertNoJavaScriptErrors();

    $role = Role::findByName('Редактор статей', Fastleo::GUARD);

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['fastleo.articles.update', 'fastleo.articles.view', 'fastleo.categories.view']);
});

it('shows the saved rights ticked when the role is opened again', function (): void {
    $role = role('Наблюдатель', ['articles.view']);
    $page = signInAndVisit(route('fastleo.resources.edit', ['roles', $role->id]));

    $page->assertChecked('[data-permission="fastleo.articles.view"]')
        ->assertNotChecked('[data-permission="fastleo.articles.update"]')
        ->assertNoJavaScriptErrors();
});
