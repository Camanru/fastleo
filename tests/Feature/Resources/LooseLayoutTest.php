<?php

declare(strict_types=1);

use Fastleo\Fastleo;

beforeEach(function (): void {
    useFixtureResources('LooseLayout');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('shows a tab placed outside Tabs as a plain group of fields', function (): void {
    $this->get(route('fastleo.resources.create', 'categories'))
        ->assertOk()
        ->assertSee('name="title"', false)
        ->assertDontSee('role="tablist"', false);
});

it('opens a collapsible section that was not collapsed', function (): void {
    $this->get(route('fastleo.resources.create', 'categories'))
        ->assertOk()
        ->assertSee('x-data="{ open: true }"', false)
        ->assertSee('name="slug"', false);
});
