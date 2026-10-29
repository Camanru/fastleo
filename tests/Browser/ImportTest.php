<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
});

it('uploads a file and shows the report', function (): void {
    $path = sys_get_temp_dir().'/fastleo-browser-import.csv';
    file_put_contents($path, "Заголовок;Статус;Просмотры\nИз файла;Черновик;12\n;;\nБез просмотров;Черновик;-1\n");

    $page = signInAndVisit(route('fastleo.resources.index', 'articles'));

    $page->press('Импорт')
        ->attach('#fastleo-import input[type=file]', $path)
        ->press('#fastleo-import button[type=submit]')
        ->assertSee('Загрузка завершена')
        ->assertSeeIn('[data-counter=created]', '1')
        ->assertSeeIn('[data-counter=skipped]', '1')
        ->assertSee('Поле «Просмотры» должно быть не меньше 0.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->where('title', 'Из файла')->value('views'))->toBe(12);
    File::delete($path);
});
