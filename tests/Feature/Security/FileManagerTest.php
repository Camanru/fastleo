<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Intervention\Gif\Builder;

beforeEach(function (): void {
    $this->disk = Storage::fake('public');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Загрузка файлов в папку менеджера от имени вошедшего админа.
 *
 * @param  array<int|string, mixed>  $files
 */
function uploadTo(mixed $folder, mixed $files): TestResponse
{
    return test()->post(route('fastleo.files.upload'), ['folder' => $folder, 'files' => $files], ['Accept' => 'application/json']);
}

/**
 * PNG, у которого в заголовке 30000×30000 пикселей: декодирование заняло бы гигабайты памяти.
 */
function pixelBomb(): string
{
    $header = 'IHDR'.pack('NN', 30_000, 30_000)."\x08\x02\x00\x00\x00";

    return "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header));
}

it('keeps the listing inside the uploads root', function (string $folder): void {
    $this->disk->put('secret.txt', 'secret');
    $this->disk->put('uploads/a.txt', 'a');

    $this->get(route('fastleo.files.index', ['folder' => $folder]))
        ->assertOk()
        ->assertSee('data-entry="uploads/a.txt"', false)
        ->assertDontSee('secret.txt');
})->with(['../..', '..', 'uploads/..', '/', 'fastleo/thumbs', 'uploads\\..', '']);

it('creates a missing folder for an upload only with a safe name', function (string $folder, bool $accepted): void {
    $response = uploadTo($folder, [UploadedFile::fake()->createWithContent('a.txt', 'a')]);

    $accepted ? $response->assertOk() : $response->assertUnprocessable()->assertJsonValidationErrors(['folder' => 'Папка не найдена.']);
    expect($this->disk->exists($folder.'/a.txt'))->toBe($accepted);
})->with([
    'plain name' => ['uploads/articles', true],
    'name with a digit and a dash' => ['uploads/news-2026', true],
    'question mark' => ['uploads/a?b', false],
    'hash' => ['uploads/a#b', false],
    'space' => ['uploads/my photos', false],
    'unsafe name under a new safe one' => ['uploads/new/a b', false],
]);

it('uploads into an existing folder whatever its name is', function (): void {
    $this->disk->makeDirectory('uploads/Старая папка');

    uploadTo('uploads/Старая папка', [UploadedFile::fake()->createWithContent('a.txt', 'a')])->assertOk();

    expect($this->disk->exists('uploads/Старая папка/a.txt'))->toBeTrue();
});

it('refuses to upload outside the uploads root', function (string $folder): void {
    uploadTo($folder, [UploadedFile::fake()->createWithContent('a.txt', 'a')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['folder' => 'Папка не найдена.']);

    expect($this->disk->allFiles())->toBe([]);
})->with(['../..', 'uploads/..', 'uploads/../fastleo', 'uploads//x', 'uploads/./x', 'uploads\\x', 'uploads/.hidden', 'other', "uploads/a\0b"]);

it('refuses executable and active files even when the config allows them', function (string $name): void {
    config(['fastleo.files.extensions' => ['jpg', 'php', 'html', 'svg', 'js', 'phtml', 'phar', 'htaccess']]);

    uploadTo('uploads', [UploadedFile::fake()->createWithContent($name, '<?php echo 1;')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files.0');

    expect($this->disk->allFiles())->toBe([]);
})->with(['shell.php', 'page.html', 'icon.svg', 'app.js', 'shell.phtml', 'shell.phar', 'shell.PHP', '.htaccess']);

it('refuses a single file sent without the array, so the allowlist cannot be skipped', function (): void {
    uploadTo('uploads', UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files');

    expect($this->disk->allFiles())->toBe([]);
});

it('replaces dots inside a name so the server cannot run it as a script', function (): void {
    uploadTo('uploads', [UploadedFile::fake()->createWithContent('shell.php.txt', 'text')])
        ->assertOk()
        ->assertJsonPath('files.0.path', 'uploads/shell-php.txt');
});

it('drops a fake picture instead of storing it', function (): void {
    uploadTo('uploads', [UploadedFile::fake()->createWithContent('fake.jpg', '<?php system($_GET["c"]);')])
        ->assertOk()
        ->assertJson(['files' => [], 'errors' => ['«fake.jpg» не открывается как картинка — не загружен.']]);

    expect($this->disk->allFiles())->toBe([]);
});

/**
 * Анимированный GIF: холст $side×$side, первый кадр во весь холст, остальные — 1×1.
 * Файл весит килобайты, а декодирование открывает каждый кадр во весь холст.
 */
function animatedGif(int $side, int $frames): string
{
    $frame = static function (int $size): string {
        $image = imagecreate($size, $size);
        imagecolorallocate($image, 0, 0, 255);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    };

    $gif = Builder::canvas($side, $side)->addFrame($frame($side), 0.1);

    for ($number = 1; $number < $frames; $number++) {
        $gif->addFrame($frame(1), 0.1, $number, 0);
    }

    return $gif->encode();
}

it('counts every frame of an animated gif against the pixel limit', function (): void {
    uploadTo('uploads', [
        UploadedFile::fake()->createWithContent('bomb.gif', animatedGif(2000, 10)),
        UploadedFile::fake()->createWithContent('banner.gif', animatedGif(100, 5)),
    ])
        ->assertOk()
        ->assertJson(['files' => [['path' => 'uploads/banner.gif']], 'errors' => ['«bomb.gif» больше 32 мегапикселей — не загружен. Уменьшите картинку.']]);

    expect($this->disk->allFiles())->toBe(['uploads/banner.gif']);
});

it('refuses a pixel bomb before decoding it', function (): void {
    uploadTo('uploads', [UploadedFile::fake()->createWithContent('bomb.png', pixelBomb())])
        ->assertOk()
        ->assertJson(['files' => [], 'errors' => ['«bomb.png» больше 32 мегапикселей — не загружен. Уменьшите картинку.']]);

    expect($this->disk->allFiles())->toBe([]);
});

it('does not make a thumbnail of a pixel bomb already on the disk', function (): void {
    $this->disk->put('uploads/bomb.png', pixelBomb());

    $this->get(route('fastleo.files.thumb', ['path' => 'uploads/bomb.png']))->assertRedirect($this->disk->url('uploads/bomb.png'));
    expect($this->disk->exists('fastleo/thumbs/uploads/bomb.png.webp'))->toBeFalse();
});

it('never deletes the root, hidden files or anything outside the uploads root', function (string $path): void {
    $this->disk->put('uploads/a.txt', 'a');
    $this->disk->put('uploads/.htaccess', 'deny');
    $this->disk->put('secret.txt', 'secret');
    $this->disk->put('fastleo/thumbs/x.webp', 'x');

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => [$path]])->assertOk()->assertJson(['deleted' => 0]);

    expect($this->disk->allFiles())->toEqualCanonicalizing(['uploads/a.txt', 'uploads/.htaccess', 'secret.txt', 'fastleo/thumbs/x.webp']);
})->with(['uploads', 'uploads/', 'uploads/.', 'uploads//', 'uploads\\a.txt', 'uploads/../secret.txt', '../.env', 'secret.txt', 'fastleo/thumbs', 'uploads/.htaccess']);

it('refuses nested arrays in the paths to delete', function (): void {
    $this->disk->put('uploads/a.txt', 'a');

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => [['uploads/a.txt']]])->assertUnprocessable();
    expect($this->disk->exists('uploads/a.txt'))->toBeTrue();
});

it('creates folders that other users of the server cannot write to', function (): void {
    $umask = umask(0);

    try {
        $this->postJson(route('fastleo.files.folder'), ['folder' => 'uploads', 'name' => 'docs'])->assertOk();
        uploadTo('uploads/new', [UploadedFile::fake()->createWithContent('a.txt', 'a')])->assertOk();
    } finally {
        umask($umask);
    }

    expect(fileperms($this->disk->path('uploads/docs')) & 0o002)->toBe(0)
        ->and(fileperms($this->disk->path('uploads/new')) & 0o002)->toBe(0);
});

it('answers array parameters without an error', function (string $route, array $query): void {
    $this->get(route($route, $query))->assertStatus($route === 'fastleo.files.thumb' ? 404 : 200);
})->with([
    ['fastleo.files.index', ['folder' => ['uploads']]],
    ['fastleo.files.index', ['current' => ['uploads/a.jpg'], 'picker' => ['field'], 'accept' => ['images']]],
    ['fastleo.files.thumb', ['path' => ['uploads/a.jpg']]],
]);

it('refuses a folder name or parent sent as an array', function (): void {
    $this->postJson(route('fastleo.files.folder'), ['folder' => ['uploads'], 'name' => ['docs']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['folder', 'name']);
});

it('does not make thumbnails outside the uploads root', function (string $path): void {
    $this->disk->put('secret.jpg', UploadedFile::fake()->image('a.jpg')->getContent());

    $this->get(route('fastleo.files.thumb', ['path' => $path]))->assertNotFound();
})->with(['secret.jpg', 'uploads/../secret.jpg', '../secret.jpg']);

it('requires a csrf token for every change', function (string $method, string $route): void {
    $this->app['env'] = 'production';

    $this->json($method, route($route), ['folder' => 'uploads', 'name' => 'x', 'paths' => ['uploads/a']])->assertStatus(419);
})->with([
    ['POST', 'fastleo.files.upload'],
    ['POST', 'fastleo.files.folder'],
    ['POST', 'fastleo.files.crop'],
    ['DELETE', 'fastleo.files.destroy'],
]);
