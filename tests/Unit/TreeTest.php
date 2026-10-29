<?php

declare(strict_types=1);

use Fastleo\Support\Tree;
use Workbench\App\Models\Category;

it('walks the tree depth first in sibling order', function (): void {
    $tree = new Tree(['1' => null, '2' => '1', '3' => '2', '4' => '1', '5' => null]);

    expect($tree->order())->toBe(['1', '2', '3', '4', '5'])
        ->and(array_map($tree->depth(...), $tree->order()))->toBe([0, 1, 2, 1, 0])
        ->and($tree->ancestors('3'))->toBe(['1', '2'])
        ->and($tree->childCount('1'))->toBe(2)
        ->and($tree->childCount('3'))->toBe(0)
        ->and($tree->has('4'))->toBeTrue()
        ->and($tree->has('9'))->toBeFalse();
});

it('loads the parent column even when the query of the section selects other columns', function (): void {
    $tree = Tree::load(Category::query()->select('title'), 'parent_id', null);

    expect($tree->order())->toBe([]);

    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    $tree = Tree::load(Category::query()->select('title')->where('title', '!=', 'none'), 'parent_id', 'sort');

    expect($tree->ancestors((string) $child->id))->toBe([(string) $parent->id]);
});

it('shows one branch with depth counted from it', function (): void {
    $tree = new Tree(['1' => null, '2' => '1', '3' => '2', '4' => null]);

    expect($tree->order('1'))->toBe(['2', '3'])
        ->and($tree->depth('3', '1'))->toBe(1)
        ->and($tree->ancestors('3', '1'))->toBe(['2'])
        ->and($tree->ancestors('4', '1'))->toBe([]);
});

it('keeps records with a lost parent, a self parent or a cycle', function (): void {
    $tree = new Tree(['1' => '9', '2' => '2', '3' => '4', '4' => '3']);

    expect($tree->order())->toBe(['1', '2', '3', '4'])
        ->and($tree->depth('1'))->toBe(0)
        ->and($tree->depth('2'))->toBe(0)
        ->and($tree->depth('4'))->toBe(1);
});

it('knows the record itself and its descendants', function (): void {
    $tree = new Tree(['1' => null, '2' => '1', '3' => '2', '4' => null]);

    expect($tree->isSelfOrDescendant('1', '1'))->toBeTrue()
        ->and($tree->isSelfOrDescendant('1', '3'))->toBeTrue()
        ->and($tree->isSelfOrDescendant('1', '4'))->toBeFalse()
        ->and($tree->isSelfOrDescendant('3', '1'))->toBeFalse();
});
