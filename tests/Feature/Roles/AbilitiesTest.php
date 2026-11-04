<?php

declare(strict_types=1);

use Fastleo\Auth\RoleMatrix;
use Symfony\Component\Finder\Finder;

it('maps every ability asked in the code to a group of the matrix', function (): void {
    $asked = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../../src')->name('*.php') as $file) {
        preg_match_all('/(?:Authorization::(?:allows|authorize|allowsOwnOr)|->(?:can|allowsOwnOr))\(\s*\'(\w+)\'(?:,\s*\'(\w+)\')?/', $file->getContents(), $matches);
        $asked = [...$asked, ...$matches[1], ...array_filter($matches[2])];
    }

    expect(array_diff(array_unique($asked), array_keys(RoleMatrix::GROUPS)))->toBe([])
        ->and($asked)->not->toBe([]);
});

it('builds the permission name from the section and the group', function (): void {
    expect(RoleMatrix::permission('articles', 'update'))->toBe('fastleo.articles.update');
});
