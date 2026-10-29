<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;

it('treats null, an empty string and an empty array as the same blank value', function (mixed $value): void {
    expect(Activity::isBlank($value))->toBeTrue()
        ->and(Activity::display($value))->toBe('—');
})->with([null, '', [[]]]);

it('does not treat zero and false as blank', function (): void {
    expect(Activity::isBlank(0))->toBeFalse()
        ->and(Activity::isBlank(false))->toBeFalse()
        ->and(Activity::display(false))->toBe('Нет');
});

it('shows a list of plain values separated by commas and other arrays as a count', function (): void {
    expect(Activity::display(['хостинг', 'сайт', 3]))->toBe('хостинг, сайт, 3')
        ->and(Activity::display([['path' => 'a.jpg'], ['path' => 'b.jpg']]))->toBe('2 элемента')
        ->and(Activity::display(['title' => 'SEO', 'robots' => 'index']))->toBe('2 элемента')
        ->and(Activity::display(array_fill(0, 5, ['x' => 1])))->toBe('5 элементов');
});
