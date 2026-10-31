<?php

declare(strict_types=1);

use Fastleo\Fastleo;

it('can be included again without redefining the helpers of the site', function (): void {
    require dirname(__DIR__, 2).'/src/helpers.php';

    expect(function_exists('fastleo_setting'))->toBeTrue()
        ->and(function_exists('fastleo_old'))->toBeTrue();
});

it('reads a site setting through the helper with a fallback', function (): void {
    expect(fastleo_setting('contacts.phone', '+7 000'))->toBe(Fastleo::setting('contacts.phone', '+7 000'));
});
