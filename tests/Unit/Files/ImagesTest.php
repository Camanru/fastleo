<?php

declare(strict_types=1);

use Fastleo\Files\Images;
use Fastleo\Files\UploadLimits;
use Illuminate\Http\UploadedFile;

/**
 * Содержимое картинки заданного размера.
 */
function pictureOf(int $width, int $height, string $name = 'a.png'): string
{
    return UploadedFile::fake()->image($name, $width, $height)->getContent();
}

beforeEach(function (): void {
    app()->setLocale('ru');
});

it('lets the picture of exactly the allowed number of pixels through and refuses one pixel row more', function (): void {
    config(['fastleo.files.max_pixels' => 100]);

    expect(Images::problem(pictureOf(10, 10)))->toBeNull()
        ->and(Images::problem(pictureOf(10, 11)))->toBe('too_large')
        ->and(Images::problem(pictureOf(1, 1)))->toBeNull();
});

it('reads the size of a picture and nothing else', function (): void {
    expect(Images::dimensions(pictureOf(30, 20)))->toBe([30, 20])
        ->and(Images::dimensions('not a picture'))->toBeNull()
        ->and(Images::problem('not a picture'))->toBe('not_image');
});

it('scales down a picture wider than the limit and leaves the others alone', function (?int $maxWidth, int $width): void {
    config(['fastleo.images.max_width' => $maxWidth]);

    expect(getimagesizefromstring(Images::normalize(pictureOf(200, 100), 'png'))[0])->toBe($width);
})->with([
    'wider' => [100, 100],
    'as wide' => [200, 200],
    'narrower than the limit' => [300, 200],
    'no limit' => [null, 200],
    'zero' => [0, 200],
]);

it('makes a thumbnail of 320 by 240 whatever the picture', function (): void {
    expect(getimagesizefromstring(Images::thumbnail(pictureOf(1000, 1000))))->toMatchArray([0 => 320, 1 => 240])
        ->and(getimagesizefromstring(Images::thumbnail(pictureOf(100, 100))))->toMatchArray([0 => 320, 1 => 240]);
});

it('writes sizes at the border of the units', function (int $bytes, string $text): void {
    expect(UploadLimits::human($bytes))->toBe($text);
})->with([
    [1023, '1023 Б'],
    [1024, '1 КБ'],
    [1_048_575, '1024 КБ'],
    [1_048_576, '1 МБ'],
    [1_073_741_824, '1 ГБ'],
    [1_610_612_736, '1,5 ГБ'],
    [1_099_511_627_776, '1024 ГБ'],
]);

it('takes the least of the limits of the server and of the site as the limit of a file', function (): void {
    config(['fastleo.files.max_size' => 1]);

    expect(UploadLimits::perFile())->toBe(1024)
        ->and(UploadLimits::kilobytes())->toBe(1);

    config(['fastleo.files.max_size' => 'big']);

    expect(UploadLimits::perFile())->toBeGreaterThan(1024);

    config(['fastleo.files.max_size' => 0]);

    expect(UploadLimits::perFile())->toBeGreaterThan(1024);
});
