<?php

declare(strict_types=1);

use Fastleo\Files\FileName;
use Fastleo\Files\FileTypes;
use Fastleo\Files\PathGuard;
use Fastleo\Files\UploadLimits;
use Illuminate\Support\Facades\Storage;

it('makes a safe latin file name', function (string $original, string $expected): void {
    expect(FileName::sanitize($original))->toBe($expected);
})->with([
    ['Фото товара.JPG', 'foto-tovara.jpg'],
    ['shell.php.txt', 'shell-php.txt'],
    ['  Прайс (2026)!.PDF ', 'prays-2026.pdf'],
    ['report', 'report'],
    ['a.j!p@g', 'a.jpg'],
]);

it('gives a random name when nothing is left after cleaning', function (): void {
    expect(FileName::sanitize('!!!.png'))->toMatch('/^[a-z0-9]{10}\.png$/');
});

it('reads php.ini sizes in bytes', function (string $value, int $bytes): void {
    expect(UploadLimits::bytes($value))->toBe($bytes);
})->with([
    ['2M', 2_097_152],
    ['512k', 524_288],
    ['1G', 1_073_741_824],
    ['1000', 1000],
    ['0', 0],
    ['-1', 0],
    ['', 0],
    ['abc', 0],
]);

it('writes sizes for people', function (int $bytes, string $text): void {
    expect(UploadLimits::human($bytes))->toBe($text);
})->with([
    [0, '0 Б'],
    [512, '512 Б'],
    [1536, '1,5 КБ'],
    [2_097_152, '2 МБ'],
]);

it('never allows executable formats and treats only known formats as pictures', function (): void {
    config([
        'fastleo.files.extensions' => ['JPG', '.png', 'php', 'svg', 'bmp', 'pdf'],
        'fastleo.files.images' => ['jpg', 'png', 'svg', 'bmp'],
    ]);

    expect(FileTypes::allowed())->toBe(['jpg', 'png', 'bmp', 'pdf'])
        ->and(FileTypes::images())->toBe(['jpg', 'png']);
});

it('refuses the rarer names of executable files that a server or a visitor machine runs', function (): void {
    config(['fastleo.files.extensions' => ['pdf', 'php2', 'php6', 'phtm', 'shtm', 'hta', 'jar', 'wasm']]);

    expect(FileTypes::allowed())->toBe(['pdf']);
});

it('refuses an empty root or the service folder as the uploads root', function (string $root): void {
    config(['fastleo.files.root' => $root]);

    PathGuard::root();
})->with(['', '/', 'fastleo', 'fastleo/files', '../uploads', 'uploads/.hidden'])->throws(LogicException::class);

it('accepts a nested uploads root', function (): void {
    config(['fastleo.files.root' => '/media/uploads/']);

    expect(PathGuard::root())->toBe('media/uploads')
        ->and(PathGuard::inside('media/uploads/a.jpg'))->toBe('media/uploads/a.jpg')
        ->and(PathGuard::inside('media/a.jpg'))->toBeNull()
        ->and(PathGuard::entry('media/uploads'))->toBeNull();
});

it('names the wrong root in the error', function (): void {
    config(['fastleo.files.root' => 'fastleo/files']);

    expect(PathGuard::root(...))->toThrow(LogicException::class, 'Недопустимый корень файлов «fastleo/files»');
});

it('lets through the root and what is inside it, but not a sibling folder that starts the same', function (): void {
    expect(PathGuard::inside('uploads'))->toBe('uploads')
        ->and(PathGuard::inside('uploads/products/a.jpg'))->toBe('uploads/products/a.jpg')
        ->and(PathGuard::inside('uploads-evil/a.jpg'))->toBeNull()
        ->and(PathGuard::inside('uploadsx'))->toBeNull();
});

it('refuses invisible and control characters and broken UTF-8 in a path', function (string $path): void {
    expect(PathGuard::inside($path))->toBeNull();
})->with([
    'zero width space' => ["uploads/a\u{200B}b.jpg"],
    'right to left override' => ["uploads/a\u{202E}gpj.exe"],
    'byte order mark' => ["uploads/\u{FEFF}a.jpg"],
    'new line' => ["uploads/a\nb.jpg"],
    'broken utf-8' => ["uploads/a\xB1.jpg"],
]);

it('lets through letters of any language and emoji in a path', function (): void {
    expect(PathGuard::inside('uploads/Фото товара/ö 🙂.jpg'))->toBe('uploads/Фото товара/ö 🙂.jpg');
});

it('accepts a path of 1024 characters and refuses 1025', function (): void {
    $path = 'uploads'.str_repeat('/'.str_repeat('a', 99), 10).'/'.str_repeat('a', 16);

    expect(strlen($path))->toBe(1024)
        ->and(PathGuard::inside($path))->toBe($path)
        ->and(PathGuard::inside($path.'a'))->toBeNull();
});

it('cuts a name and a folder at 100 characters', function (): void {
    expect(FileName::sanitize(str_repeat('a', 150).'.jpg'))->toBe(str_repeat('a', 100).'.jpg')
        ->and(FileName::folder(str_repeat('b', 150)))->toBe(str_repeat('b', 100));
});

it('numbers the taken names up to ten and then adds a random suffix', function (): void {
    $disk = Storage::fake('public');
    $disk->put('f/a.jpg', 'x');

    foreach (range(1, 9) as $number) {
        $disk->put('f/a-'.$number.'.jpg', 'x');
    }

    expect(FileName::unique($disk, 'f', 'a.jpg'))->toBe('f/a-10.jpg');

    $disk->put('f/a-10.jpg', 'x');

    expect(FileName::unique($disk, 'f', 'a.jpg'))->toMatch('~^f/a-[a-z0-9]{8}\.jpg$~');
});

it('numbers a name without an extension', function (): void {
    $disk = Storage::fake('public');
    $disk->put('f/readme', 'x');

    expect(FileName::unique($disk, 'f', 'readme'))->toBe('f/readme-1')
        ->and(FileName::unique($disk, 'f', 'free.txt'))->toBe('f/free.txt');
});
