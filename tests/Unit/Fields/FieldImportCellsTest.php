<?php

declare(strict_types=1);

use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Time;

describe('checkbox', function (): void {
    it('reads yes and no words of a spreadsheet cell', function (mixed $cell, ?string $expected): void {
        expect(Checkbox::make('flag')->importValue($cell))->toBe($expected);
    })->with([
        'empty cell' => [null, '0'],
        'empty string' => ['', '0'],
        'zero' => ['0', '0'],
        'word no' => ['Нет', '0'],
        'dash' => ['-', '0'],
        'false' => [false, '0'],
        'one' => [1.0, '1'],
        'word yes' => ['ДА', '1'],
        'plus' => ['+', '1'],
        'something else' => ['возможно', 'возможно'],
    ]);
});

describe('time', function (): void {
    it('reads a time of a spreadsheet cell without seconds', function (mixed $cell, ?string $expected): void {
        expect(Time::make('opens_at')->importValue($cell))->toBe($expected);
    })->with([
        'excel time' => [new DateTimeImmutable('1899-12-30 09:05:00'), '09:05'],
        'with seconds' => ['09:30:15', '09:30'],
        'without seconds' => ['09:30', '09:30'],
        'unclear text' => ['утром', 'утром'],
        'empty cell' => [null, null],
    ]);
});

describe('date and time', function (): void {
    it('reads a datetime of a spreadsheet cell in the form format', function (mixed $cell, ?string $expected): void {
        expect(DateTime::make('starts_at')->importValue($cell))->toBe($expected);
    })->with([
        'excel date' => [new DateTimeImmutable('2026-03-08 12:30:45'), '2026-03-08T12:30'],
        'iso with seconds' => ['2026-03-08 12:30:45', '2026-03-08T12:30'],
        'russian format' => ['08.03.2026 12:30', '2026-03-08T12:30'],
        'empty cell' => [null, null],
    ]);
});

describe('lists', function (): void {
    it('splits the paths of a gallery cell by commas and line breaks', function (): void {
        expect(Gallery::make('photos')->importValue("uploads/a.jpg, uploads/b.jpg\r\nuploads/c.jpg\n\n"))->toBe(['uploads/a.jpg', 'uploads/b.jpg', 'uploads/c.jpg'])
            ->and(Gallery::make('photos')->importValue(null))->toBe([]);
    });

    it('gives an empty list for an empty cell of a field with several values', function (): void {
        expect(BelongsToMany::make('topics')->importValue(null))->toBe([])
            ->and(BelongsToMany::make('topics')->importValue('1, 2'))->toBe(['1', '2']);
    });
});
