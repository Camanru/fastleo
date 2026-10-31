<?php

declare(strict_types=1);

use Fastleo\Publishing\FitsMessengers;

/**
 * Прогнать правило и собрать сообщения об ошибках.
 *
 * @return list<string>
 */
function fitsMessengersErrors(mixed $value): array
{
    $errors = [];

    new FitsMessengers()->validate('text', $value, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

it('lets a text that fits both messengers through', function (): void {
    expect(fitsMessengersErrors('**Акция** до пятницы'))->toBe([]);
});

it('refuses a text that does not fit and names the limit', function (): void {
    expect(fitsMessengersErrors(str_repeat('а', 4001)))->toHaveCount(1)->and(fitsMessengersErrors(str_repeat('а', 4001))[0])->toContain('4000');
});

it('leaves a value that is not a string to the other rules of the field', function (): void {
    expect(fitsMessengersErrors(['не', 'строка']))->toBe([])->and(fitsMessengersErrors(null))->toBe([]);
});
