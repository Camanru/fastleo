<?php

declare(strict_types=1);

use Fastleo\Support\RecordKey;
use Fastleo\Tests\Fixtures\Models\TreeNode;
use Workbench\App\Models\Category;

it('accepts only digits for a number key', function (string $value, bool $valid): void {
    expect(RecordKey::valid(new Category, $value))->toBe($valid);
})->with([
    'number' => ['15', true],
    'eighteen digits' => [str_repeat('9', 18), true],
    'nineteen digits' => [str_repeat('9', 19), false],
    'letters' => ['abc', false],
    'sign' => ['+5', false],
    'trailing line break' => ["5\n", false],
]);

it('accepts only a real uuid for a model with uuid keys', function (string $value, bool $valid): void {
    expect(RecordKey::valid(new TreeNode, $value))->toBe($valid);
})->with([
    'uuid' => ['01a1880e-c544-730b-b5b7-f9fc07e5c5e4', true],
    'upper case uuid' => ['01A1880E-C544-730B-B5B7-F9FC07E5C5E4', true],
    'word' => ['abc', false],
    'number' => ['15', false],
    'short uuid' => ['01a1880e-c544-730b-b5b7', false],
]);

it('knows the exact format only for numbers, uuid and ulid', function (): void {
    expect(RecordKey::strict(new Category, '1'))->toBeTrue()
        ->and(RecordKey::strict(new TreeNode, 'abc'))->toBeFalse();
});
