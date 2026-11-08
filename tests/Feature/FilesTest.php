<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Files\UploadLimits;
use Fastleo\Tests\Fixtures\Files\UndeletableDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->disk = Storage::fake('public');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('sends a guest to the login page and refuses uploads without a session', function (): void {
    auth(Fastleo::GUARD)->logout();

    $this->get(route('fastleo.files.index'))->assertRedirect(route('fastleo.login'));
    $this->postJson(route('fastleo.files.upload'), ['folder' => 'uploads'])->assertUnauthorized();
});

it('shows folders and files of the current folder with breadcrumbs', function (): void {
    $this->disk->put('uploads/products/kettle.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    $this->disk->put('uploads/products/price.pdf', '%PDF');
    $this->disk->makeDirectory('uploads/products/archive');
    $this->disk->put('uploads/products/.gitignore', '*');

    $this->get(route('fastleo.files.index', ['folder' => 'uploads/products']))
        ->assertOk()
        ->assertSee('data-entry="uploads/products/archive"', false)
        ->assertSee('data-entry="uploads/products/kettle.jpg"', false)
        ->assertSee('data-entry="uploads/products/price.pdf"', false)
        ->assertDontSee('.gitignore')
        ->assertSee('aria-current="page">products</span>', false)
        ->assertSee('id="fastleo-menu"', false);
});

it('opens the picker without the menu, in the folder of the current file, with images only', function (): void {
    $this->disk->put('uploads/products/kettle.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    $this->disk->put('uploads/products/price.pdf', '%PDF');

    $this->get(route('fastleo.files.index', ['picker' => 'field', 'accept' => 'images', 'current' => 'uploads/products/kettle.jpg']))
        ->assertOk()
        ->assertDontSee('id="fastleo-menu"', false)
        ->assertSee('data-current', false)
        ->assertSee('data-entry="uploads/products/kettle.jpg"', false)
        ->assertDontSee('price.pdf');
});

it('stores an upload under a safe latin name, shrinks a wide picture and never overwrites', function (): void {
    config(['fastleo.images.max_width' => 1024]);

    $first = $this->post(route('fastleo.files.upload'), [
        'folder' => 'uploads',
        'files' => [UploadedFile::fake()->image('Фото товара.JPG', 2048, 1024)],
    ], ['Accept' => 'application/json']);

    $first->assertOk()->assertJson(['files' => [['path' => 'uploads/foto-tovara.jpg', 'name' => 'foto-tovara.jpg', 'image' => true]], 'errors' => []]);
    expect(getimagesizefromstring((string) $this->disk->get('uploads/foto-tovara.jpg')))->toMatchArray([0 => 1024, 1 => 512]);

    $this->post(route('fastleo.files.upload'), [
        'folder' => 'uploads',
        'files' => [UploadedFile::fake()->image('фото товара.jpg', 100, 100)],
    ], ['Accept' => 'application/json'])->assertJsonPath('files.0.path', 'uploads/foto-tovara-1.jpg');

    expect(getimagesizefromstring((string) $this->disk->get('uploads/foto-tovara.jpg')))->toMatchArray([0 => 1024, 1 => 512]);
});

it('names every file in the errors of the answer when the site allows no extension at all', function (): void {
    config(['fastleo.files.extensions' => []]);

    $this->postJson(route('fastleo.files.upload'), ['folder' => 'uploads', 'files' => [UploadedFile::fake()->image('a.jpg')]])
        ->assertOk()
        ->assertJsonPath('files', [])
        ->assertJsonCount(1, 'errors');

    expect($this->disk->allFiles('uploads'))->toBe([]);
});

it('stores the files that pass and names the ones that do not, instead of refusing the whole request for one of them', function (): void {
    $response = $this->post(route('fastleo.files.upload'), [
        'folder' => 'uploads',
        'files' => [UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->createWithContent('page.html', '<script>1</script>'), UploadedFile::fake()->image('third.jpg')],
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('files.0.path', 'uploads/first.jpg')
        ->assertJsonPath('files.1.path', 'uploads/third.jpg')
        ->assertJsonCount(2, 'files')
        ->assertJsonCount(1, 'errors');

    expect($response->json('errors.0'))->toStartWith('Файл «page.html» должен иметь расширение: ')
        ->and($this->disk->allFiles('uploads'))->toBe(['uploads/first.jpg', 'uploads/third.jpg']);
});

it('names a nested list in place of a file instead of failing, and stores the file next to it', function (): void {
    $response = $this->post(route('fastleo.files.upload'), [
        'folder' => 'uploads',
        'files' => [[UploadedFile::fake()->image('b.jpg')], UploadedFile::fake()->image('a.jpg')],
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(1, 'files');

    expect($response->json('errors'))->not->toBeEmpty()
        ->and($this->disk->allFiles('uploads'))->toBe(['uploads/a.jpg']);
});

it('stores other files as they are and creates the folder of a field', function (): void {
    $this->post(route('fastleo.files.upload'), [
        'folder' => 'uploads/prices',
        'files' => [UploadedFile::fake()->createWithContent('Прайс 2026.pdf', '%PDF-1.4 прайс')],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('files.0.path', 'uploads/prices/prays-2026.pdf');

    expect($this->disk->get('uploads/prices/prays-2026.pdf'))->toBe('%PDF-1.4 прайс');
});

it('creates a folder with a latin name and refuses a duplicate or an empty name', function (): void {
    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => 'Новости 2026'])
        ->assertOk()
        ->assertJson(['path' => 'uploads/novosti-2026']);

    expect($this->disk->directoryExists('uploads/novosti-2026'))->toBeTrue();

    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => 'новости 2026'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'Папка «novosti-2026» уже есть.']);

    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => '!!!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'Имя папки должно содержать буквы или цифры.']);
});

it('does not create a folder inside a folder that does not exist', function (): void {
    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads/missing', 'name' => 'Новости'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['folder']);

    expect($this->disk->directoryExists('uploads/missing/novosti'))->toBeFalse();
});

it('says so instead of reporting a deletion when the disk does not let the file go, and names the file', function (): void {
    $this->disk->put('uploads/a.txt', 'x');
    Storage::set('public', UndeletableDisk::at($this->disk->path('')));

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/a.txt']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['paths' => '«a.txt» не удалось удалить: диск не дал это сделать.']);

    expect($this->disk->exists('uploads/a.txt'))->toBeTrue();
});

it('deletes selected files and folders with their thumbnails', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    $this->disk->put('uploads/old/b.jpg', UploadedFile::fake()->image('b.jpg')->getContent());
    $this->disk->put('uploads/keep.txt', 'keep');
    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/a.jpg']));
    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/old/b.jpg']));

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/a.jpg', 'uploads/old', 'uploads/missing.jpg']])
        ->assertOk()
        ->assertJson(['deleted' => 2]);

    expect($this->disk->exists('uploads/a.jpg'))->toBeFalse()
        ->and($this->disk->exists('uploads/old'))->toBeFalse()
        ->and($this->disk->exists('fastleo/thumbs/uploads/a.jpg.webp'))->toBeFalse()
        ->and($this->disk->exists('fastleo/thumbs/uploads/old'))->toBeFalse()
        ->and($this->disk->get('uploads/keep.txt'))->toBe('keep');
});

it('hides folders and files named in files.hidden and does not delete them one by one', function (): void {
    config(['fastleo.files.hidden' => ['thumbs', '*.bak']]);
    $this->disk->put('uploads/products/thumbs/a.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    $this->disk->put('uploads/products/kettle.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    $this->disk->put('uploads/products/old.bak', 'x');
    $this->disk->put('uploads/products/Thumbs-list.txt', 'x');

    $this->get(route('fastleo.files.index', ['folder' => 'uploads/products']))
        ->assertOk()
        ->assertSee('data-entry="uploads/products/kettle.jpg"', false)
        ->assertSee('data-entry="uploads/products/Thumbs-list.txt"', false)
        ->assertDontSee('data-entry="uploads/products/thumbs"', false)
        ->assertDontSee('data-entry="uploads/products/old.bak"', false);

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/products/thumbs', 'uploads/products/old.bak', 'uploads/products/kettle.jpg']])
        ->assertOk()
        ->assertJson(['deleted' => 1]);

    expect($this->disk->exists('uploads/products/thumbs/a.jpg'))->toBeTrue()
        ->and($this->disk->exists('uploads/products/old.bak'))->toBeTrue()
        ->and($this->disk->exists('uploads/products/kettle.jpg'))->toBeFalse();
});

it('hides the content of a hidden folder too: no listing, no picker, no deleting inside', function (): void {
    config(['fastleo.files.hidden' => 'thumbs']);
    $this->disk->put('uploads/products/thumbs/a.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    $this->disk->put('uploads/products/kettle.jpg', UploadedFile::fake()->image('b.jpg')->getContent());

    $this->get(route('fastleo.files.index', ['folder' => 'uploads/products/thumbs']))
        ->assertOk()
        ->assertDontSee('data-entry="uploads/products/thumbs/a.jpg"', false)
        ->assertDontSee('data-entry="uploads/products/kettle.jpg"', false);
    $this->get(route('fastleo.files.index', ['picker' => 'field', 'current' => 'uploads/products/thumbs/a.jpg']))
        ->assertOk()
        ->assertDontSee('data-entry="uploads/products/thumbs/a.jpg"', false);

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/products/thumbs/a.jpg']])->assertOk()->assertJson(['deleted' => 0]);

    expect($this->disk->exists('uploads/products/thumbs/a.jpg'))->toBeTrue();
});

it('creates a thumbnail on the first view and sends to it', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 1200, 600)->getContent());

    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/a.jpg']))
        ->assertRedirect($this->disk->url('fastleo/thumbs/uploads/a.jpg.webp'));

    expect(getimagesizefromstring((string) $this->disk->get('fastleo/thumbs/uploads/a.jpg.webp')))
        ->toMatchArray([0 => 320, 1 => 240]);
});

it('sends to the file itself when a thumbnail cannot be made, and 404 when there is no picture', function (): void {
    $this->disk->put('uploads/broken.jpg', 'not a picture');
    $this->disk->put('uploads/price.pdf', '%PDF');

    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/broken.jpg']))->assertRedirect($this->disk->url('uploads/broken.jpg'));
    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/missing.jpg']))->assertNotFound();
    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/price.pdf']))->assertNotFound();
    expect($this->disk->exists('fastleo/thumbs/uploads/broken.jpg.webp'))->toBeFalse();
});

it('shows the files item in the menu and php upload limits in the page', function (): void {
    $this->get(route('fastleo.dashboard'))->assertSee('href="'.route('fastleo.files.index').'"', false);

    $this->get(route('fastleo.files.index'))
        ->assertSee('data-max-file="'.UploadLimits::perFile().'"', false)
        ->assertSee('data-max-total="'.UploadLimits::total().'"', false)
        ->assertSee('data-max-files="'.UploadLimits::files().'"', false);

    // Окно выбора файла в форме берёт те же лимиты: PHP отбрасывает файлы сверх max_file_uploads без единой ошибки.
    $this->get(route('fastleo.resources.create', 'articles'))->assertSee('data-max-files="'.UploadLimits::files().'"', false);
});

it('tells how many files one request takes: what PHP takes, but no more than the server accepts', function (): void {
    expect(UploadLimits::files())->toBe(UploadLimits::files((int) ini_get('max_file_uploads')))->toBeBetween(1, 50)
        ->and(UploadLimits::files(20))->toBe(20)
        ->and(UploadLimits::files(50))->toBe(50)
        // Хост с max_file_uploads=100: пачка в 100 файлов получила бы 422 целиком.
        ->and(UploadLimits::files(100))->toBe(50)
        ->and(UploadLimits::files(0))->toBe(50);
});

it('refuses more files than one request takes, as the file window never sends them', function (): void {
    $files = array_map(fn (int $number): UploadedFile => UploadedFile::fake()->image('photo-'.$number.'.jpg'), range(1, 51));

    $this->postJson(route('fastleo.files.upload'), ['folder' => 'uploads', 'files' => $files])->assertJsonValidationErrors('files');

    expect($this->disk->allFiles('uploads'))->toBe([]);
});

it('limits the file size by the config', function (): void {
    config(['fastleo.files.max_size' => 1]);

    $this->post(route('fastleo.files.upload'), [
        'folder' => 'uploads',
        'files' => [UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 2048))],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('files', [])->assertJsonCount(1, 'errors');

    expect($this->disk->exists('uploads/big.txt'))->toBeFalse();
});

it('offers to sign in in a new tab when the login page opens inside the picker', function (): void {
    auth(Fastleo::GUARD)->logout();

    $this->get(route('fastleo.login'))->assertSee('data-framed-login', false)->assertSee('Войти в новой вкладке');
});

it('saves a cropped copy next to the picture and keeps the original', function (): void {
    $this->disk->put('uploads/photo.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());

    $this->postJson(route('fastleo.files.crop'), ['path' => 'uploads/photo.jpg', 'x' => 100, 'y' => 50, 'width' => 300, 'height' => 400, 'ratio' => '3:4'])
        ->assertOk()
        ->assertJson(['file' => ['path' => 'uploads/photo-crop.jpg', 'name' => 'photo-crop.jpg', 'image' => true]]);

    expect(getimagesizefromstring((string) $this->disk->get('uploads/photo-crop.jpg')))->toMatchArray([0 => 300, 1 => 400])
        ->and(getimagesizefromstring((string) $this->disk->get('uploads/photo.jpg')))->toMatchArray([0 => 800, 1 => 600]);

    $this->postJson(route('fastleo.files.crop'), ['path' => 'uploads/photo.jpg', 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 600])
        ->assertJsonPath('file.path', 'uploads/photo-crop-1.jpg');
});

it('refuses a crop out of the picture, in another ratio or of something that is not a picture', function (array $data, string $key, string $message): void {
    $this->disk->put('uploads/photo.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());
    $this->disk->put('uploads/fake.jpg', 'not a picture');
    $this->disk->put('secret.jpg', UploadedFile::fake()->image('a.jpg')->getContent());

    $this->postJson(route('fastleo.files.crop'), [...['path' => 'uploads/photo.jpg', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 400], ...$data])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);

    expect($this->disk->files('uploads'))->toEqualCanonicalizing(['uploads/photo.jpg', 'uploads/fake.jpg']);
})->with([
    'out of the picture' => [['x' => 600, 'width' => 300], 'path', 'Рамка выходит за картинку.'],
    'another ratio' => [['ratio' => '16:9'], 'ratio', 'Рамка должна быть в пропорции 16:9.'],
    'not a picture' => [['path' => 'uploads/fake.jpg'], 'path', 'Картинку «fake.jpg» не удалось открыть.'],
    'missing' => [['path' => 'uploads/missing.jpg'], 'path', 'Картинку «missing.jpg» не удалось открыть.'],
    'outside the root' => [['path' => 'secret.jpg'], 'path', 'Файл не найден в менеджере файлов.'],
]);

it('keeps the file manager closed for an admin the site Gate refuses, but not the field picker', function (): void {
    $this->disk->put('uploads/products/kettle.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    Gate::define('manageFastleoFiles', fn (): bool => false);

    $this->get(route('fastleo.files.index'))->assertForbidden();
    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/products/kettle.jpg']])->assertForbidden();
    $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('href="'.route('fastleo.files.index').'"', false);
    $this->disk->assertExists('uploads/products/kettle.jpg');

    $this->get(route('fastleo.files.index', ['picker' => 'field']))->assertOk();
    $this->postJson(route('fastleo.files.upload'), ['folder' => 'uploads', 'files' => [UploadedFile::fake()->image('new.jpg')]])->assertOk();
    $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => 'novaya'])->assertOk();
});

it('lets an admin the site Gate allows manage files', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    Gate::define('manageFastleoFiles', fn (): bool => true);

    $this->get(route('fastleo.files.index'))->assertOk();
    $this->get(route('fastleo.dashboard'))->assertSee('href="'.route('fastleo.files.index').'"', false);
    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/a.jpg']])->assertOk()->assertJsonPath('deleted', 1);
});

it('answers with the errors of a refused file whose name is not utf-8 and still stores the other files of the batch', function (): void {
    $response = $this->postJson(route('fastleo.files.upload'), [
        'folder' => 'uploads',
        'files' => [
            UploadedFile::fake()->createWithContent('ok.txt', 'hello'),
            UploadedFile::fake()->createWithContent("bad\xff.php", '<?php'),
            UploadedFile::fake()->createWithContent("bad\xff.exe", 'MZ'),
        ],
    ])->assertOk();

    expect($response->json('files.0.path'))->toBe('uploads/ok.txt')
        ->and($response->json('errors'))->toHaveCount(2)
        ->and($response->json('errors.0'))->toContain('bad?.php')
        ->and($this->disk->allFiles())->toBe(['uploads/ok.txt']);
});
