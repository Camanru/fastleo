<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('adds a block, finds a product on the server and saves the choice', function (): void {
    $kit = Product::factory()->create(['title' => 'Набор', 'status' => 'active']);
    Product::factory()->create(['title' => 'Кабель']);
    $lamp = Product::factory()->create(['title' => 'Лампа торшерная']);
    $combobox = '[data-blocks=bundleItems] [data-combobox^="bundleItems.new-"]';

    $page = signInAndVisit(route('fastleo.resources.edit', ['products', $kit->id]));

    $page->click('[role=tab]:has-text("Комплект")')
        ->click('[data-blocks=bundleItems] [data-block-add]')
        ->click($combobox.' input[role=combobox]')
        ->type($combobox.' input[role=combobox]', 'торшер')
        ->assertSeeIn($combobox.' [role=listbox]', 'Лампа торшерная')
        ->assertDontSeeIn($combobox.' [role=listbox]', 'Кабель')
        ->click($combobox.' [role=option]')
        ->fill('[data-blocks=bundleItems] input[name$="[qty]"]', '4')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect($kit->bundleItems()->get()->map(fn ($row): array => [$row->item_id, $row->qty])->all())->toBe([[$lamp->id, 4]]);
});
