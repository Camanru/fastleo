<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\TextSample;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('renders the custom promo code field with its own template', function (): void {
    $this->get(route('fastleo.resources.create', 'text-samples'))
        ->assertOk()
        ->assertSee('data-promo-code', false)
        ->assertSee('name="promo_code"', false);
});

it('saves the promo code in capitals and shows it as code in the list', function (): void {
    $this->post(route('fastleo.resources.store', 'text-samples'), ['title' => 'Скидка', 'promo_code' => 'sale-10', 'source' => 'admin'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(TextSample::query()->sole()->promo_code)->toBe('SALE-10');

    $this->get(route('fastleo.resources.index', 'text-samples'))->assertSee('<code>SALE-10</code>', false);
});

it('rejects a promo code with characters other than latin letters, digits and dash', function (): void {
    $this->post(route('fastleo.resources.store', 'text-samples'), ['title' => 'Скидка', 'promo_code' => 'скидка 10', 'source' => 'admin'])
        ->assertSessionHasErrors('promo_code');

    expect(TextSample::query()->count())->toBe(0);
});
