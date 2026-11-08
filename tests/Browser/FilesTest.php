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
    $this->disk->put('uploads/foto-tovara.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());

    $page = signInAndVisit(route('fastleo.files.index'));

    $page->assertPresent('[data-entry="uploads/foto-tovara.jpg"]')
        ->click('button:has-text("Новая папка")')
        ->fill('[data-folder-name]', 'Акции')
        ->press('Создать')
        ->assertPresent('[data-entry="uploads/aktsii"]')
        ->fill('[data-files-filter]', 'foto')
        ->assertVisible('[data-entry="uploads/foto-tovara.jpg"]')
        ->assertScript("getComputedStyle(document.querySelector('[data-entry=\"uploads/aktsii\"]')).display", 'none')
        ->fill('[data-files-filter]', '')
        ->click('[data-entry="uploads/foto-tovara.jpg"] button[title="foto-tovara.jpg"]')
        ->click('[data-files-delete]')
        ->click('[data-confirm-ok]')
        ->assertMissing('[data-entry="uploads/foto-tovara.jpg"]')
        ->assertNoJavaScriptErrors();

    expect($this->disk->exists('uploads/aktsii'))->toBeTrue()
        ->and($this->disk->exists('uploads/foto-tovara.jpg'))->toBeFalse();
});

// Запрос подменён: тестовый сервер Pest не разбирает multipart как PHP, а здесь важно, сколькими пачками уходит выбор.
it('sends a big selection in batches that PHP takes, so none of the files is dropped silently', function (): void {
    $page = signInAndVisit(route('fastleo.files.index'));

    $page->script(<<<'SCRIPT'
        window.__batches = [];
        window.fetch = async (url, options) => {
            window.__batches.push(options.body.getAll('files[]').length);

            return new Response(JSON.stringify({ files: [], errors: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        };
        const input = document.querySelector('[data-files-input]');
        const files = new DataTransfer();
        for (let number = 0; number < 45; number++) {
            files.items.add(new File(['x'], 'file-' + number + '.txt', { type: 'text/plain' }));
        }
        input.files = files.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    SCRIPT);

    waitUntil($page, '(window.__batches ?? []).reduce((sum, count) => sum + count, 0) === 45');

    $page->assertScript('window.__batches.reduce((sum, count) => sum + count, 0)', 45)
        ->assertScript('Math.max(...window.__batches) <= Number(document.querySelector("[x-data=fastleoFiles]").dataset.maxFiles)', true)
        ->assertNoJavaScriptErrors();
});

// Запрос подменён: в одном из файлов второй пачки сервер нашёл недопустимый. Он отвечает 200 и называет такой файл в `errors`
// (допустимые уже сохранены), а целиком запрос отклоняет 422 (нет папки, больше 50 файлов). Остальные пачки идут дальше, ошибка видна.
it('goes on with the next batches when one of them has a refused file or is refused', function (string $second): void {
    $page = signInAndVisit(route('fastleo.files.index'));

    $page->script(<<<SCRIPT
        window.__second = {$second};
        window.__calls = 0;
        window.fetch = async (url, options) => {
            if (!(options?.body instanceof FormData)) {
                return new Response('<div></div>', { status: 200 });
            }

            window.__calls++;

            return window.__calls === 2
                ? window.__second
                : new Response(JSON.stringify({ files: [{ path: 'uploads/x.txt', url: '/x', name: 'x.txt', image: false }], errors: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        };
        const root = document.querySelector('[x-data=fastleoFiles]');
        root.dataset.maxFiles = '20';
        const input = document.querySelector('[data-files-input]');
        const files = new DataTransfer();
        for (let number = 0; number < 45; number++) {
            files.items.add(new File(['x'], 'file-' + number + '.txt', { type: 'text/plain' }));
        }
        input.files = files.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    SCRIPT);

    waitUntil($page, 'window.__calls >= 3 && document.querySelector("[data-files-errors]") !== null');

    $page->assertScript('window.__calls', 3)
        ->assertSee('Файл «page.html» не принят.')
        ->assertNoJavaScriptErrors();
})->with([
    'a refused file in the answer of 200' => ["new Response(JSON.stringify({ files: [], errors: ['Файл «page.html» не принят.'] }), { status: 200, headers: { 'Content-Type': 'application/json' } })"],
    'a batch refused as a whole by 422' => ["new Response(JSON.stringify({ errors: { 'files.3': ['Файл «page.html» не принят.'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } })"],
]);

// Запросы подменены: каждая пачка должна уместиться в post_max_size (maxTotal), а не весь выбор.
it('splits a selection by size so that each batch fits the request limit of the server', function (): void {
    $page = signInAndVisit(route('fastleo.files.index'));

    $page->script(<<<'SCRIPT'
        window.__sizes = [];
        window.fetch = async (url, options) => {
            if (options?.body instanceof FormData) {
                window.__sizes.push(options.body.getAll('files[]').reduce((sum, file) => sum + file.size, 0));
            }

            return new Response(JSON.stringify({ files: [], errors: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        };
        const root = document.querySelector('[x-data=fastleoFiles]');
        window.__limit = 3000;
        root.dataset.maxTotal = String(window.__limit);
        root.dataset.maxFile = '0';
        root.dataset.maxFiles = '0';
        const input = document.querySelector('[data-files-input]');
        const files = new DataTransfer();
        for (let number = 0; number < 6; number++) {
            files.items.add(new File(['x'.repeat(1000)], 'file-' + number + '.txt', { type: 'text/plain' }));
        }
        input.files = files.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    SCRIPT);

    waitUntil($page, '(window.__sizes ?? []).reduce((sum, size) => sum + size, 0) === 6000');

    // Шесть файлов по 1000 байт при пределе 3000: две пачки по три, а не одна на 6000.
    $page->assertScript('window.__sizes.length', 2)
        ->assertScript('Math.max(...window.__sizes) <= window.__limit', true)
        ->assertNoJavaScriptErrors();
});

// Запросы подменены: первая пачка проходит, вторая падает. Файлы первой уже на диске — человек должен увидеть и их, и ошибку.
it('keeps what the earlier batches uploaded and shows the error when a later batch fails', function (): void {
    $page = signInAndVisit(route('fastleo.files.index'));

    $page->script(<<<'SCRIPT'
        window.__uploads = 0;
        window.__refreshed = false;
        window.fetch = async (url, options) => {
            if (options?.body instanceof FormData) {
                window.__uploads++;

                return window.__uploads === 1
                    ? new Response(JSON.stringify({ files: [{ path: 'uploads/file-0.txt', url: '/x', name: 'file-0.txt', image: false }], errors: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })
                    : new Response('{}', { status: 500, headers: { 'Content-Type': 'application/json' } });
            }

            // Список обновляется запросом той же страницы; остальные запросы (счётчик уведомлений) в счёт не идут.
            window.__refreshed = window.__refreshed || url === location.href;

            return new Response('<div></div>', { status: 200 });
        };
        const input = document.querySelector('[data-files-input]');
        const files = new DataTransfer();
        for (let number = 0; number < 45; number++) {
            files.items.add(new File(['x'], 'file-' + number + '.txt', { type: 'text/plain' }));
        }
        input.files = files.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    SCRIPT);

    waitUntil($page, 'window.__refreshed === true && document.querySelector("[data-files-errors]") !== null');

    $page->assertScript('window.__refreshed', true)
        ->assertVisible('[data-files-errors]')
        ->assertNoJavaScriptErrors();
});

// Запрос подменён: диск отказал на втором файле. Первый уже удалён, поэтому список обновляется и после сбоя.
it('refreshes the list when deleting fails halfway, because part of the selection may be gone already', function (): void {
    $this->disk->put('uploads/foto-tovara.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());

    $page = signInAndVisit(route('fastleo.files.index'));

    $page->script(<<<'SCRIPT'
        window.__refreshed = false;
        window.fetch = async (url, options) => {
            if (options?.method === 'DELETE') {
                return new Response(JSON.stringify({ message: 'x', errors: { paths: ['Не удалось удалить файл.'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } });
            }

            // Список обновляется запросом той же страницы; остальные запросы (счётчик уведомлений) в счёт не идут.
            window.__refreshed = window.__refreshed || url === location.href;

            return new Response('<div></div>', { status: 200 });
        };
    SCRIPT);

    $page->click('[data-entry="uploads/foto-tovara.jpg"] button[title="foto-tovara.jpg"]')
        ->click('[data-files-delete]')
        ->click('[data-confirm-ok]');

    waitUntil($page, 'window.__refreshed === true');

    $page->assertScript('window.__refreshed', true)
        ->assertSee('Не удалось удалить файл.')
        ->assertNoJavaScriptErrors();
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
