<?php

declare(strict_types=1);

use Fastleo\Fields\File;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Workbench\App\Models\Category;

it('uploads into the folder of the field inside the uploads root', function (): void {
    expect(Image::make('photo')->uploadFolder())->toBe('uploads')
        ->and(Image::make('photo')->directory('/doctors/')->uploadFolder())->toBe('uploads/doctors')
        ->and(Image::make('photo')->directory('doctors/2026-photos')->uploadFolder())->toBe('uploads/doctors/2026-photos');
});

it('refuses a folder name that the file manager would not create', function (string $directory): void {
    Image::make('photo')->directory($directory);
})->throws(LogicException::class)->with([
    'capital letters' => ['Doctors'],
    'underscore' => ['doctor_photos'],
    'parent folder' => ['../secret'],
    'empty segment' => ['doctors//photos'],
]);

it('suggests the right folder name in the message', function (): void {
    expect(fn (): Image => Image::make('photo')->directory('Doctor_Photos/2026'))
        ->toThrow(LogicException::class, 'doctor-photos/2026');
});

it('takes only pictures for images and the allowed extensions for files', function (): void {
    config(['fastleo.files.extensions' => ['jpg', 'png', 'pdf', 'zip']]);

    expect(Image::make('photo')->acceptedExtensions())->toBe(['jpg', 'png'])
        ->and(Gallery::make('photos')->extensions(['PNG', 'pdf'])->acceptedExtensions())->toBe(['png'])
        ->and(File::make('price')->acceptedExtensions())->toBe(['jpg', 'png', 'pdf', 'zip'])
        ->and(File::make('price')->extensions(['.pdf', 'exe'])->acceptedExtensions())->toBe(['pdf']);
});

it('opens the picker in the folder of the field or of the current file', function (): void {
    $image = Image::make('photo')->directory('doctors');

    expect($image->pickerUrl())->toBe(route('fastleo.files.index', ['picker' => 'field', 'accept' => 'images', 'folder' => 'uploads/doctors']))
        ->and($image->pickerUrl('uploads/a.jpg'))->toBe(route('fastleo.files.index', ['picker' => 'field', 'accept' => 'images', 'current' => 'uploads/a.jpg']))
        ->and(File::make('price')->pickerUrl(multiple: true))->toBe(route('fastleo.files.index', ['picker' => 'field', 'multiple' => '1', 'folder' => 'uploads']));
});

it('keeps gallery paths without empty values and repeats', function (): void {
    expect(Gallery::make('photos')->dehydrate([' uploads/a.jpg ', '', 'uploads/a.jpg', 5, 'uploads/b.jpg']))->toBe(['uploads/a.jpg', 'uploads/b.jpg'])
        ->and(Gallery::make('photos')->dehydrate('["uploads/c.jpg"]'))->toBe(['uploads/c.jpg'])
        ->and(Gallery::make('photos')->dehydrate(null))->toBe([])
        ->and(Gallery::make('photos')->max(0)->getMax())->toBe(1)
        ->and(Gallery::make('photos')->getMax())->toBe(100)
        ->and(Gallery::make('photos')->getRules(null)['photos'])->toContain('max:100');
});

it('needs an array cast for a gallery', function (): void {
    Gallery::make('title')->fill(new Category, ['uploads/a.jpg']);
})->throws(LogicException::class, 'добавьте колонке каст');

it('takes a crop ratio as width:height', function (): void {
    expect(Image::make('photo')->crop('3:4')->getCrop())->toBe('3:4')
        ->and(Image::make('photo')->getCrop())->toBeNull();

    Image::make('photo')->crop('0.75');
})->throws(InvalidArgumentException::class, 'ширина:высота');
