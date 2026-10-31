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
