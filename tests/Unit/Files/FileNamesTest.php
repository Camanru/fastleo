<?php

declare(strict_types=1);

use Fastleo\Files\FileName;
use Fastleo\Files\FileTypes;
use Fastleo\Files\PathGuard;
use Fastleo\Files\UploadLimits;

it('makes a safe latin file name', function (string $original, string $expected): void {
    expect(FileName::sanitize($original))->toBe($expected);
})->with([
    ['Фото врача.JPG', 'foto-vracha.jpg'],
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
