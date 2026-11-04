<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;
use Workbench\App\Models\Product;
use Workbench\App\Models\Remark;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('picks a section, searches its records and resets the record when the section changes', function (): void {
    Article::factory()->create(['title' => 'Приточная вентиляция']);
    $lamp = Product::factory()->create(['title' => 'Лампа торшерная', 'is_visible' => true]);
    $combobox = '[data-morph-to] [data-combobox]';

    $page = signInAndVisit(route('fastleo.resources.create', 'remarks'));

    $page->fill('body', 'Проверить цену')
        ->select('#field-subject', 'articles')
        ->click($combobox.' input[role=combobox]')
        ->type($combobox.' input[role=combobox]', 'вентиляц')
        ->assertSeeIn($combobox.' [role=listbox]', 'Приточная вентиляция')
        ->click($combobox.' [role=option]')
        ->assertScript("document.querySelector('[data-morph-to] input[name=\"subject[id]\"]').value !== ''", true)
        ->select('#field-subject', 'products')
        ->assertScript("document.querySelector('[data-morph-to] input[name=\"subject[id]\"]').value", '')
        ->click($combobox.' input[role=combobox]')
        ->type($combobox.' input[role=combobox]', 'торшер')
        ->assertSeeIn($combobox.' [role=listbox]', 'Лампа торшерная')
        ->assertDontSeeIn($combobox.' [role=listbox]', 'Приточная вентиляция')
        ->click($combobox.' [role=option]')
        ->press('Сохранить')
        ->assertSee('Сохранено')
        ->assertNoJavaScriptErrors();

    $remark = Remark::query()->sole();

    expect([$remark->subject_type, $remark->subject_id])->toBe([Product::class, $lamp->id]);
});
