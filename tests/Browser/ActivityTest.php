<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('shows the change in the history of a record and reverts it after a confirmation', function (): void {
    $topic = Topic::factory()->create(['title' => 'Первая версия']);

    $page = signInAndVisit(route('fastleo.resources.edit', ['topics', $topic->id]));

    $page->fill('title', 'Вторая версия')
        ->press('Сохранить')
        ->click('a[href$="/history"]')
        ->assertSee('Изменено')
        ->assertSee('Первая версия')
        ->assertSee('Вторая версия')
        ->click('[data-activity-revert]')
        ->assertScript("document.getElementById('fastleo-confirm').open")
        ->click('#fastleo-confirm [data-confirm-primary]')
        ->assertSee('Готово: запись возвращена.')
        ->assertSee('Возврат прежних значений')
        ->assertNoJavaScriptErrors();

    expect($topic->refresh()->title)->toBe('Первая версия');
});
