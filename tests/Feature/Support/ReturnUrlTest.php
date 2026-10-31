<?php

declare(strict_types=1);

use Fastleo\Support\ReturnUrl;

it('keeps an address of the admin and drops everything else', function (string $suffix, bool $kept): void {
    $url = route('fastleo.dashboard').$suffix;

    expect(ReturnUrl::sanitize($url))->toBe($kept ? $url : null);
})->with([
    'a page of a section' => ['/articles?page=2', true],
    'a record' => ['/articles/5/edit?return=%2Ffastleo%2Farticles', true],
    'a way out of the folder' => ['/../go', false],
    'a way out in the middle' => ['/articles/../../go', false],
    'an encoded way out' => ['/%2e%2e/go', false],
    'an encoded way out in capitals' => ['/%2E%2E/go', false],
    'a dot in the query only' => ['/articles?q=a..b', true],
]);

it('drops addresses that are not strings or lead to another site', function (mixed $url): void {
    expect(ReturnUrl::sanitize($url))->toBeNull();
})->with([
    'null' => [null],
    'a list' => [['/fastleo/articles']],
    'another site' => ['https://evil.example/fastleo/articles'],
    'without the folder' => ['/articles'],
]);
