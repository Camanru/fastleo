<?php

declare(strict_types=1);

use Fastleo\Files\Images;
use Fastleo\Files\Variants;
use Illuminate\Support\Facades\Storage;

/**
 * Двоичное содержимое картинки, нарисованной GD.
 *
 * @param  'gif'|'png'  $format
 */
function drawnImage(string $format): string
{
    $image = imagecreatetruecolor(20, 20);

    ob_start();
    $format === 'gif' ? imagegif($image) : imagepng($image);

    return (string) ob_get_clean();
}

it('counts a gif that cannot be read as one frame', function (): void {
    $truncated = substr(drawnImage('gif'), 0, 16);

    expect(Images::dimensions($truncated))->toBe([20, 20])
        ->and(Images::problem($truncated))->toBeNull();
});

it('makes no variants of a picture whose body cannot be decoded and removes the old ones', function (): void {
    $disk = Storage::fake('public');
    config(['fastleo.images.presets' => ['small' => ['widths' => [10]]]]);
    $disk->put('uploads/broken.png', substr(drawnImage('png'), 0, 40));
    $disk->put('fastleo/variants/small/uploads/broken.png/10.webp', 'old');

    expect(Images::dimensions((string) $disk->get('uploads/broken.png')))->toBe([20, 20])
        ->and(Variants::generate('uploads/broken.png', 'small'))->toBe([])
        ->and($disk->exists('fastleo/variants/small/uploads/broken.png'))->toBeFalse();
});

/**
 * GIF из заданного числа кадров 1×1 (без разбора данных: только блоки файла); у первого кадра — локальная палитра, перед каждым — расширение.
 */
function gifOfFrames(int $frames, bool $withGlobalPalette = false): string
{
    $gif = 'GIF89a'.pack('vv', 1, 1).($withGlobalPalette ? "\x80\x00\x00".str_repeat("\x00", 6) : "\x00\x00\x00");

    for ($frame = 0; $frame < $frames; $frame++) {
        $local = $frame === 0;
        $gif .= "\x21\xF9\x04\x00\x00\x00\x00\x00"
            .",\x00\x00\x00\x00".pack('vv', 1, 1).($local ? "\x80".str_repeat("\x00", 6) : "\x00")
            ."\x02\x02\x44\x01\x00";
    }

    return $gif.';';
}

it('counts the frames of a gif by its blocks, without decoding them, and refuses a gif of tens of thousands of tiny frames', function (): void {
    $limit = Images::MAX_GIF_FRAMES;

    expect(Images::problem(gifOfFrames(3)))->toBeNull()
        ->and(Images::problem(gifOfFrames(3, withGlobalPalette: true)))->toBeNull()
        ->and(Images::problem(gifOfFrames($limit)))->toBeNull()
        ->and(Images::problem(gifOfFrames($limit + 1)))->toBe('too_large')
        ->and(Images::problem(gifOfFrames($limit + 1, withGlobalPalette: true)))->toBe('too_large')
        // Обрыв посреди блока и мусор вместо блока: считаются как есть, без ошибок.
        ->and(Images::problem(substr(gifOfFrames(5), 0, 40)))->toBeNull()
        ->and(Images::problem('GIF89a'.pack('vv', 1, 1)."\x00\x00\x00\x99garbage"))->toBeNull();
});

it('measures the pixels of a gif by all its frames', function (): void {
    config(['fastleo.files.max_pixels' => 4]);

    expect(Images::problem(gifOfFrames(4)))->toBeNull()
        ->and(Images::problem(gifOfFrames(5)))->toBe('too_large');
});

it('keeps the ready variants when this process lacks the memory to open the picture, but removes them when the file is gone', function (): void {
    $disk = Storage::fake('public');
    config(['fastleo.images.presets' => ['small' => ['widths' => [10]]], 'fastleo.files.max_pixels' => 10_000_000_000]);
    $png = drawnImage('png');
    // Заголовок объявляет 15 000 × 15 000 точек: по размеру картинка допустима, но в памяти процесса (в консоли — другой предел, чем в php-fpm) не откроется.
    $heavy = substr($png, 0, 16).pack('NN', 15_000, 15_000).substr($png, 24);
    $disk->put('uploads/big.png', $heavy);
    $disk->put('fastleo/variants/small/uploads/big.png/10.webp', 'old');
    // Предел памяти задаём сами: в CI он снят (-1), и картинка «поместилась» бы.
    $memoryLimit = ini_get('memory_limit');
    ini_set('memory_limit', '512M');

    try {
        expect(Images::problem($heavy))->toBe('no_memory')
            ->and(Variants::generate('uploads/big.png', 'small'))->toBe([])
            ->and($disk->exists('fastleo/variants/small/uploads/big.png/10.webp'))->toBeTrue();

        $disk->delete('uploads/big.png');

        expect(Variants::generate('uploads/big.png', 'small'))->toBe([])
            ->and($disk->exists('fastleo/variants/small/uploads/big.png'))->toBeFalse();
    } finally {
        ini_set('memory_limit', $memoryLimit);
    }
});
