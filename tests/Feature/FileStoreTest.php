<?php

declare(strict_types=1);

use Fastleo\Files\FileRejected;
use Fastleo\Files\FileStore;
use Fastleo\Tests\Fixtures\Files\UnwritableDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * PNG с верным заголовком (размер читается), но без данных: GD её не откроет.
 */
function truncatedPng(): string
{
    return substr((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true), 0, 33).'broken';
}

beforeEach(function (): void {
    $this->disk = Storage::fake('public');
    app()->setLocale('ru');
});

it('refuses a forbidden extension before anything is written', function (): void {
    expect(fn (): string => FileStore::store(UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'), 'uploads'))
        ->toThrow(FileRejected::class, '«shell.php»: такие файлы загружать нельзя.');

    expect($this->disk->allFiles())->toBe([]);
});

it('refuses a picture whose header is fine but whose data does not decode', function (): void {
    expect(fn (): string => FileStore::store(UploadedFile::fake()->createWithContent('photo.png', truncatedPng()), 'uploads'))
        ->toThrow(FileRejected::class, '«photo.png» не открывается как картинка — не загружен.');

    expect($this->disk->exists('uploads/photo.png'))->toBeFalse();
});

it('reports a disk that cannot write instead of returning a path of a missing file', function (string $name, string $content): void {
    Storage::set('public', UnwritableDisk::at($this->disk->path('')));

    expect(fn (): string => FileStore::store(UploadedFile::fake()->createWithContent($name, $content), 'uploads'))
        ->toThrow(FileRejected::class, '«'.$name.'» не удалось записать на диск.');
})->with([
    'a plain file' => ['price.txt', 'text'],
    'a picture' => ['photo.png', fn () => UploadedFile::fake()->image('a.png', 20, 20)->getContent()],
]);

it('refuses to crop a picture whose data does not decode', function (): void {
    $this->disk->put('uploads/photo.png', truncatedPng());

    expect(fn (): string => FileStore::crop('uploads/photo.png', 0, 0, 1, 1))
        ->toThrow(FileRejected::class, 'Картинку «photo.png» не удалось открыть.');

    expect($this->disk->allFiles('uploads'))->toBe(['uploads/photo.png']);
});

it('reports a disk that cannot write the crop instead of returning a path of a missing file', function (): void {
    $this->disk->put('uploads/photo.png', UploadedFile::fake()->image('a.png', 20, 20)->getContent());
    Storage::set('public', UnwritableDisk::at($this->disk->path('')));

    expect(fn (): string => FileStore::crop('uploads/photo.png', 0, 0, 5, 5))
        ->toThrow(FileRejected::class, '«photo.png» не удалось записать на диск.');

    expect($this->disk->allFiles('uploads'))->toBe(['uploads/photo.png']);
});

it('returns the existing thumbnail without making it again', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());

    $path = FileStore::thumbnail('uploads/a.jpg');
    $this->disk->put($path, 'cached');

    expect($path)->toBe('fastleo/thumbs/uploads/a.jpg.webp')
        ->and(FileStore::thumbnail('uploads/a.jpg'))->toBe($path)
        ->and($this->disk->get($path))->toBe('cached');
});

it('makes no thumbnail for a picture whose data does not decode', function (): void {
    $this->disk->put('uploads/photo.png', truncatedPng());

    expect(FileStore::thumbnail('uploads/photo.png'))->toBeNull()
        ->and($this->disk->exists('fastleo/thumbs/uploads/photo.png.webp'))->toBeFalse();
});
