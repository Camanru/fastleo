<?php

declare(strict_types=1);

use Fastleo\Files\FileRejected;
use Fastleo\Files\FileStore;
use Fastleo\Files\ImageSupport;
use Fastleo\Tests\Fixtures\Files\UndeletableDisk;
use Fastleo\Tests\Fixtures\Files\UnwritableDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * PNG с верным заголовком (размер читается), но без данных: GD её не откроет.
 */
function truncatedPng(): string
{
    return substr(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true), 0, 33).'broken';
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

it('waits for a taken lock of the name before it gives up and writes without it', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('fastleo.upload.'.md5('uploads/price.txt'), 30);
    $lock->get();

    $path = FileStore::store(UploadedFile::fake()->createWithContent('price.txt', 'text'), 'uploads');

    expect($path)->toBe('uploads/price.txt')
        ->and($this->disk->get($path))->toBe('text');
    // Пять секунд ожидания шагами по 250 мс (шаг ожидания блокировки задаёт Laravel): запись не обошла блокировку сразу.
    Sleep::assertSleptTimes(19);
});

it('writes under the lock when it is free soon, and releases it', function (): void {
    $path = FileStore::store(UploadedFile::fake()->createWithContent('price.txt', 'text'), 'uploads');

    expect($path)->toBe('uploads/price.txt')
        ->and(Cache::lock('fastleo.upload.'.md5('uploads/price.txt'), 1)->get())->toBeTrue();
});

it('encodes the path of a file in its address so that a space, a hash or a question mark do not break the link', function (): void {
    expect(FileStore::url('uploads/My file #1?.jpg'))->toBe('/storage/uploads/My%20file%20%231%3F.jpg')
        ->and(FileStore::url('uploads/Фото 100%.jpg'))->toBe('/storage/uploads/%D0%A4%D0%BE%D1%82%D0%BE%20100%25.jpg')
        ->and(FileStore::url('uploads/a.jpg'))->toBe('/storage/uploads/a.jpg');
});

it('makes no thumbnail when GD is built without WebP, so the manager shows the original instead of an error', function (): void {
    $this->disk->put('uploads/photo.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());
    app()->instance(ImageSupport::class, new ImageSupport(['gd', 'exif'], IMG_JPG | IMG_PNG));

    expect(FileStore::thumbnail('uploads/photo.jpg'))->toBeNull()
        ->and($this->disk->allFiles('fastleo'))->toBe([]);
});

it('refuses a picture that GD cannot save in the format of its name instead of failing with an error of PHP', function (): void {
    app()->instance(ImageSupport::class, new ImageSupport(['gd', 'exif'], IMG_JPG | IMG_PNG));
    // Файл по содержимому PNG, по имени WebP: декодер его прочтёт, а `imagewebp()` в этой GD нет.
    $png = UploadedFile::fake()->image('a.png', 40, 30)->getContent();

    expect(fn (): string => FileStore::store(UploadedFile::fake()->createWithContent('photo.webp', $png), 'uploads'))
        ->toThrow(FileRejected::class, 'не открывается как картинка')
        ->and($this->disk->allFiles('uploads'))->toBe([]);

    $this->disk->put('uploads/photo.webp', $png);

    expect(fn (): string => FileStore::crop('uploads/photo.webp', 0, 0, 10, 10))->toThrow(FileRejected::class);
});

it('reports a disk that cannot create a folder instead of returning its path', function (): void {
    Storage::set('public', UndeletableDisk::at($this->disk->path('')));

    expect(fn (): string => FileStore::makeFolder('uploads', 'Новости'))
        ->toThrow(FileRejected::class, 'Папку «novosti» не удалось создать');
});

it('reports a disk that does not let a file or a folder go instead of saying it is deleted', function (string $path): void {
    $this->disk->put('uploads/docs/a.txt', 'x');
    Storage::set('public', UndeletableDisk::at($this->disk->path('')));

    expect(fn (): bool => FileStore::delete($path))->toThrow(FileRejected::class, 'не удалось удалить');

    expect($this->disk->exists('uploads/docs/a.txt'))->toBeTrue();
})->with(['a file' => 'uploads/docs/a.txt', 'a folder' => 'uploads/docs']);

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

it('gives no thumbnail when the disk cannot write it, so the manager shows the original', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());
    Storage::set('public', UnwritableDisk::at($this->disk->path('')));

    expect(FileStore::thumbnail('uploads/a.jpg'))->toBeNull()
        ->and($this->disk->exists('fastleo/thumbs/uploads/a.jpg.webp'))->toBeFalse();
});

it('returns the existing thumbnail without making it again', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());

    $path = FileStore::thumbnail('uploads/a.jpg');
    $this->disk->put($path, 'cached');

    expect($path)->toBe('fastleo/thumbs/uploads/a.jpg.webp')
        ->and(FileStore::thumbnail('uploads/a.jpg'))->toBe($path)
        ->and($this->disk->get($path))->toBe('cached');
});

it('still stores a file when the cache has no locks or the lock of the name is busy', function (): void {
    $store = fn (): string => FileStore::store(UploadedFile::fake()->createWithContent('price.txt', 'x'), 'uploads');

    config(['cache.default' => 'null']);
    resolve('cache')->forgetDriver();
    expect($store())->toBe('uploads/price.txt');

    config(['cache.default' => 'array']);
    resolve('cache')->forgetDriver();
    $lock = Cache::lock('fastleo.upload.'.md5('uploads/price.txt'), 30);
    $lock->get();

    expect($store())->toBe('uploads/price-1.txt');
    $lock->release();
});

it('makes the thumbnail again when the picture was replaced under the same name', function (): void {
    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 800, 600)->getContent());
    $path = FileStore::thumbnail('uploads/a.jpg');
    $this->disk->put($path, 'stale');
    touch($this->disk->path($path), time() - 100);

    $this->disk->put('uploads/a.jpg', UploadedFile::fake()->image('a.jpg', 400, 400)->getContent());
    touch($this->disk->path('uploads/a.jpg'), time());

    expect(FileStore::thumbnail('uploads/a.jpg'))->toBe($path)
        ->and($this->disk->get($path))->not->toBe('stale');
});

it('makes no thumbnail for a picture whose data does not decode', function (): void {
    $this->disk->put('uploads/photo.png', truncatedPng());

    expect(FileStore::thumbnail('uploads/photo.png'))->toBeNull()
        ->and($this->disk->exists('fastleo/thumbs/uploads/photo.png.webp'))->toBeFalse();
});

it('crops exactly inside the picture and refuses a frame that sticks out by one pixel or has no size', function (): void {
    $this->disk->put('uploads/photo.png', UploadedFile::fake()->image('a.png', 100, 80)->getContent());

    expect(FileStore::crop('uploads/photo.png', 0, 0, 100, 80))->toBe('uploads/photo-crop.png')
        ->and(FileStore::crop('uploads/photo.png', 99, 79, 1, 1))->toBe('uploads/photo-crop-1.png');

    foreach ([[1, 0, 100, 80], [0, 1, 100, 80], [0, 0, 101, 80], [0, 0, 100, 81], [-1, 0, 10, 10], [0, -1, 10, 10], [0, 0, 0, 10], [0, 0, 10, 0]] as $frame) {
        expect(fn (): string => FileStore::crop('uploads/photo.png', ...$frame))->toThrow(FileRejected::class, 'Рамка выходит за картинку.');
    }
});

it('says the size limit of a picture in whole megapixels', function (int $limit, int $width, int $height, string $megapixels): void {
    config(['fastleo.files.max_pixels' => $limit]);

    expect(fn (): string => FileStore::store(UploadedFile::fake()->image('big.png', $width, $height), 'uploads'))
        ->toThrow(FileRejected::class, '«big.png» больше '.$megapixels.' мегапикселей');
})->with([
    'rounds down' => [1_400_000, 1500, 1000, '1'],
    'rounds up' => [2_500_000, 2000, 1500, '3'],
]);

it('sorts the folder like a person: by the natural order, ignoring the case, and hides what the site hid', function (): void {
    config(['fastleo.files.hidden' => ['thumbs', '*.bak']]);

    foreach (['uploads/img10.jpg', 'uploads/img2.jpg', 'uploads/Img1.jpg', 'uploads/old.bak', 'uploads/.hidden', 'uploads/b/x.jpg', 'uploads/a10/x.jpg', 'uploads/a2/x.jpg', 'uploads/thumbs/x.jpg'] as $path) {
        $this->disk->put($path, 'x');
    }

    $listing = FileStore::listing('uploads');

    expect(array_column($listing['files'], 'name'))->toBe(['Img1.jpg', 'img2.jpg', 'img10.jpg'])
        ->and(array_column($listing['folders'], 'name'))->toBe(['a2', 'a10', 'b']);
});

it('checks every part of the path against the hidden names', function (): void {
    config(['fastleo.files.hidden' => ['thumbs', '*.bak']]);

    expect(FileStore::isHidden('uploads/thumbs/a.jpg'))->toBeTrue()
        ->and(FileStore::isHidden('uploads/a.bak'))->toBeTrue()
        ->and(FileStore::isHidden('uploads/a.jpg'))->toBeFalse()
        ->and(FileStore::isHidden('uploads//a.jpg'))->toBeFalse();

    config(['fastleo.files.hidden' => 'thumbs']);
    expect(FileStore::isHidden('uploads/thumbs'))->toBeTrue();

    config(['fastleo.files.hidden' => [5, null, 'thumbs']]);
    expect(FileStore::isHidden('uploads/5/a.jpg'))->toBeFalse()->and(FileStore::isHidden('thumbs'))->toBeTrue();

    config(['fastleo.files.hidden' => []]);
    expect(FileStore::isHidden('uploads/thumbs'))->toBeFalse();
});

it('deletes the thumbnails of what it deletes and leaves the thumbnails of the others', function (): void {
    foreach (['uploads/d/a.jpg', 'fastleo/thumbs/uploads/d/a.jpg.webp', 'uploads/e/b.jpg', 'fastleo/thumbs/uploads/e/b.jpg.webp', 'uploads/c.jpg', 'fastleo/thumbs/uploads/c.jpg.webp'] as $path) {
        $this->disk->put($path, 'x');
    }

    expect(FileStore::delete('uploads/d'))->toBeTrue()
        ->and(FileStore::delete('uploads/c.jpg'))->toBeTrue()
        ->and(FileStore::delete('uploads/missing.jpg'))->toBeFalse()
        ->and($this->disk->allFiles())->toEqualCanonicalizing(['uploads/e/b.jpg', 'fastleo/thumbs/uploads/e/b.jpg.webp']);
});

it('makes the folder of the upload when it does not exist yet', function (): void {
    FileStore::store(UploadedFile::fake()->createWithContent('price.txt', 'x'), 'uploads/new');

    expect($this->disk->directoryExists('uploads/new'))->toBeTrue();
});

it('refuses an animated gif whose frame lies outside its canvas instead of failing the whole request', function (): void {
    $frame = imagecreatetruecolor(40, 40);
    $second = imagecreatetruecolor(40, 40);
    ob_start();
    imagegif($frame);
    $gif = (string) ob_get_clean();
    // Экран 20×20 при кадре 40×40: такой файл читается по заголовку, а кадр декодер вырезает за пределами холста.
    $gif = substr($gif, 0, 6).pack('vv', 20, 20).substr($gif, 10);
    unset($second);

    expect(fn (): string => FileStore::store(UploadedFile::fake()->createWithContent('anim.gif', $gif), 'uploads'))
        ->toThrow(FileRejected::class, '«anim.gif» не открывается как картинка — не загружен.');

    expect($this->disk->exists('uploads/anim.gif'))->toBeFalse();
});
