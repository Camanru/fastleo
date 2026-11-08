<?php

declare(strict_types=1);

use Fastleo\Files\ImageSupport;

it('reports nothing missing when GD has JPEG and WebP and exif is loaded', function (): void {
    expect(new ImageSupport(['Core', 'GD', 'exif'], IMG_JPG | IMG_WEBP | IMG_PNG)->missing())->toBe([]);
});

it('reports GD alone when it is not loaded, whatever the formats say', function (): void {
    expect(new ImageSupport(['exif'], IMG_JPG | IMG_WEBP)->missing())->toBe(['gd']);
});

it('names the formats GD is built without', function (int $types, array $missing): void {
    expect(new ImageSupport(['gd', 'exif'], $types)->missing())->toBe($missing);
})->with([
    'no webp' => [IMG_JPG, ['webp']],
    'no jpeg' => [IMG_WEBP, ['jpeg']],
    'neither' => [IMG_PNG, ['webp', 'jpeg']],
]);

it('can encode webp only with GD built with it', function (array $extensions, int $types, bool $expected): void {
    expect(new ImageSupport($extensions, $types)->canEncodeWebp())->toBe($expected);
})->with([
    'GD with webp' => [['gd'], IMG_JPG | IMG_WEBP, true],
    'GD without webp' => [['gd'], IMG_JPG, false],
    'no GD' => [['exif'], IMG_WEBP, false],
]);

it('reports exif separately from GD', function (): void {
    expect(new ImageSupport(['gd'], IMG_JPG | IMG_WEBP)->missing())->toBe(['exif'])
        ->and(new ImageSupport([], 0)->missing())->toBe(['gd', 'exif']);
});

it('looks at the real PHP by default', function (): void {
    $gd = extension_loaded('gd');
    $expected = [
        ...($gd ? array_keys(array_filter(['webp' => IMG_WEBP, 'jpeg' => IMG_JPG], fn (int $flag): bool => (imagetypes() & $flag) === 0)) : ['gd']),
        ...(extension_loaded('exif') ? [] : ['exif']),
    ];

    expect(new ImageSupport()->missing())->toBe($expected);
});

it('can encode an extension only with GD built with its format', function (string $extension, array $extensions, int $types, bool $expected): void {
    expect(new ImageSupport($extensions, $types)->canEncode($extension))->toBe($expected);
})->with([
    'png needs only GD' => ['png', ['gd'], IMG_PNG, true],
    'gif needs only GD' => ['GIF', ['gd'], IMG_PNG, true],
    'png without GD' => ['png', ['exif'], IMG_PNG, false],
    'jpg with jpeg' => ['jpg', ['gd'], IMG_JPG, true],
    'jpeg without jpeg' => ['jpeg', ['gd'], IMG_PNG, false],
    'webp with webp' => ['webp', ['gd'], IMG_WEBP, true],
    'webp without webp' => ['WEBP', ['gd'], IMG_JPG, false],
]);
