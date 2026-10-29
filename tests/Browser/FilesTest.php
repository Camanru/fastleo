<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Artisan::call('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
    admin(['email' => 'admin@example.com']);
    $this->disk = Storage::fake('public');
});

// Загрузку здесь не проверить: тестовый сервер Pest не разбирает поля `files[]` в multipart так, как PHP.
// Её покрывают tests/Feature/FilesTest.php и tests/Feature/Security/FileManagerTest.php.
it('filters files, creates a folder and deletes the selection', function (): void {
    $this->disk->put('uploads/foto-vracha.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());

    $page = signInAndVisit(route('fastleo.files.index'));

    $page->assertPresent('[data-entry="uploads/foto-vracha.jpg"]')
        ->click('button:has-text("Новая папка")')
        ->fill('[data-folder-name]', 'Акции')
        ->press('Создать')
        ->assertPresent('[data-entry="uploads/aktsii"]')
        ->fill('[data-files-filter]', 'foto')
        ->assertVisible('[data-entry="uploads/foto-vracha.jpg"]')
        ->assertScript("getComputedStyle(document.querySelector('[data-entry=\"uploads/aktsii\"]')).display", 'none')
        ->fill('[data-files-filter]', '')
        ->click('[data-entry="uploads/foto-vracha.jpg"] button[title="foto-vracha.jpg"]')
        ->click('[data-files-delete]')
        ->click('[data-confirm-ok]')
        ->assertMissing('[data-entry="uploads/foto-vracha.jpg"]')
        ->assertNoJavaScriptErrors();

    expect($this->disk->exists('uploads/aktsii'))->toBeTrue()
        ->and($this->disk->exists('uploads/foto-vracha.jpg'))->toBeFalse();
});

it('picks an image for a field in the file window and saves it', function (): void {
    $this->disk->put('uploads/articles/cover.jpg', UploadedFile::fake()->image('a.jpg')->getContent());

    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->fill('title', 'Статья')
        ->select('status', 'draft')
        ->click('[role=tab]:has-text("Медиа")')
        ->click('[data-field="cover"] [data-file-choose]')
        ->withinFrame('#fastleo-picker iframe', function ($frame): void {
            $frame->click('button[title="cover.jpg"]');
        })
        ->assertScript("document.getElementById('fastleo-picker').open", false)
        ->assertValue('[name=cover]', 'uploads/articles/cover.jpg')
        ->assertSeeIn('[data-field="cover"] [data-file-name]', 'cover.jpg')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->cover)->toBe('uploads/articles/cover.jpg');
});

it('adds several pictures to a gallery in the file window and removes one', function (): void {
    foreach (['1', '2', '3'] as $name) {
        $this->disk->put("uploads/articles/{$name}.jpg", UploadedFile::fake()->image('a.jpg')->getContent());
    }

    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->fill('title', 'Статья')
        ->select('status', 'draft')
        ->click('[role=tab]:has-text("Медиа")')
        ->click('[data-gallery-choose]')
        ->withinFrame('#fastleo-picker iframe', function ($frame): void {
            $frame->click('button[title="1.jpg"]')
                ->click('button[title="3.jpg"]')
                ->click('button[title="2.jpg"]')
                ->click('[data-files-choose]');
        })
        ->assertScript("[...document.querySelectorAll('[name=\"gallery[]\"]')].map((input) => input.value).join(',')", 'uploads/articles/1.jpg,uploads/articles/3.jpg,uploads/articles/2.jpg')
        ->click('[data-gallery-item]:nth-child(2) button')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->gallery)->toBe(['uploads/articles/1.jpg', 'uploads/articles/2.jpg']);
});

it('crops the cover in the ratio of the field and picks the copy', function (): void {
    $cover = UploadedFile::fake()->image('a.jpg', 1600, 1200)->getContent();
    $this->disk->put('uploads/articles/cover.jpg', $cover);
    $article = Article::factory()->create(['cover' => 'uploads/articles/cover.jpg']);

    // Тестовый сервер не отдаёт /storage — подставляем картинку для рамки, обрезает сервер по пути.
    $page = signInAndVisit(route('fastleo.resources.edit', ['articles', $article->id]));

    $page->click('[role=tab]:has-text("Медиа")')
        ->script("document.querySelector('[data-field=\"cover\"] [data-file-field]')._x_dataStack[0].url = 'data:image/jpeg;base64,".base64_encode($cover)."'");

    $page->click('[data-field="cover"] [data-file-crop]')
        ->assertVisible('#fastleo-cropper .cropper-container')
        ->click('[data-cropper-apply]')
        ->assertValue('[name=cover]', 'uploads/articles/cover-crop.jpg')
        ->assertNoJavaScriptErrors();

    $size = getimagesizefromstring((string) $this->disk->get('uploads/articles/cover-crop.jpg'));
    expect($size[0] / $size[1])->toEqualWithDelta(16 / 9, 0.02);
});

it('edits a rich text in the editor and inserts a picture from the file window', function (): void {
    $this->disk->put('uploads/articles/photo.jpg', UploadedFile::fake()->image('a.jpg', 300, 200)->getContent());

    $page = signInAndVisit(route('fastleo.resources.create', 'articles'));

    $page->fill('title', 'Статья')
        ->select('status', 'draft')
        ->click('[role=tab]:has-text("Содержание")')
        ->assertVisible('[data-rich-text] .tox-hugerte')
        ->withinFrame('[data-rich-text] iframe', function ($editor): void {
            $editor->click('.mce-content-body')->type('.mce-content-body', 'Текст статьи');
        })
        ->click('[data-rich-text] button[aria-label="Вставить/изменить изображение"]')
        ->click('.tox-dialog .tox-browse-url')
        ->withinFrame('#fastleo-picker iframe', function ($frame): void {
            $frame->click('[data-entry="uploads/articles"] a');
        })
        ->withinFrame('#fastleo-picker iframe', function ($frame): void {
            $frame->click('button[title="photo.jpg"]');
        })
        ->assertValue('.tox-dialog input[type=url]', '/storage/uploads/articles/photo.jpg')
        ->click('.tox-dialog button:has-text("Сохранить")')
        ->press('Сохранить')
        ->assertSee('Сохранено.')
        ->assertNoJavaScriptErrors();

    expect(Article::query()->sole()->content)
        ->toContain('Текст статьи')
        ->toContain('<img src="/storage/uploads/articles/photo.jpg"');
});
