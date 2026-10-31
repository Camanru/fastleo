<?php

declare(strict_types=1);

use Fastleo\Support\Html\SanitizeDiff;

it('cuts both texts around the first difference and escapes line breaks', function (): void {
    $before = str_repeat('Привет, мир! ', 10)."\n<div>цена &l</div>".str_repeat(' конец', 30);
    $after = str_repeat('Привет, мир! ', 10)."\n<div>цена &amp;l</div>".str_repeat(' конец', 30);

    [$cutBefore, $cutAfter] = SanitizeDiff::around($before, $after);

    expect($cutBefore)->toStartWith('…')->toEndWith('…')->toContain('цена &l')->not->toContain("\n")
        ->and($cutAfter)->toContain('цена &amp;l')
        ->and(mb_strlen($cutBefore))->toBeLessThanOrEqual(SanitizeDiff::SNIPPET + 4);
});

it('does not split a multibyte character at the first difference', function (): void {
    [$before, $after] = SanitizeDiff::around('Жук', 'Жил');

    expect($before)->toBe('Жук')->and($after)->toBe('Жил');
});

it('shows a text that only ends differently', function (): void {
    [$before, $after] = SanitizeDiff::around('<p>a</p>', '<p>a</p><br>');

    expect($before)->toBe('<p>a</p>')->and($after)->toBe('<p>a</p><br>');
});

it('steps back to the first byte of a multibyte character that differs only in its last byte', function (): void {
    [$before, $after] = SanitizeDiff::around('Жи', 'Жй');

    expect($before)->toBe('Жи')->and($after)->toBe('Жй');
});
