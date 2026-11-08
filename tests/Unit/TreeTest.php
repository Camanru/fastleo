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

it('drops the order of the section together with its parameters', function (): void {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);

    $tree = Tree::load(Category::query()->orderByRaw('title = ? desc', ['x']), 'parent_id', 'sort');

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

it('hands out the records of one level and each of them with all its descendants', function (): void {
    $tree = new Tree(['1' => null, '2' => '1', '3' => '2', '4' => '1', '5' => null, '6' => '5']);

    expect($tree->roots())->toBe(['1', '5'])
        ->and($tree->roots('1'))->toBe(['2', '4'])
        ->and($tree->roots('3'))->toBe([])
        ->and($tree->subtrees(['1']))->toBe(['1', '2', '3', '4'])
        ->and($tree->subtrees(['5', '1']))->toBe(['5', '6', '1', '2', '3', '4'])
        ->and($tree->subtrees($tree->roots()))->toBe($tree->order())
        ->and($tree->subtrees(['2', '4']))->toBe(['2', '3', '4'])
        ->and($tree->subtrees(['9']))->toBe([]);
});

it('counts records with a lost parent or a cycle among the roots', function (): void {
    $tree = new Tree(['1' => '9', '2' => '2', '3' => '4', '4' => '3']);

    expect($tree->roots())->toBe(['1', '2', '3'])
        ->and($tree->subtrees($tree->roots()))->toBe(['1', '2', '3', '4']);
});

it('cuts the tail of a big branch so that a page does not exceed the limit and every record of the level stays on it', function (): void {
    $tree = new Tree(['1' => null, '2' => '1', '3' => '1', '4' => '1', '5' => '1', '6' => null, '7' => '6', '8' => null]);

    expect($tree->page(['1', '6', '8'], 100))->toBe(['keys' => ['1', '2', '3', '4', '5', '6', '7', '8'], 'truncated' => false])
        ->and($tree->page(['1', '6', '8'], 5))->toBe(['keys' => ['1', '2', '3', '6', '8'], 'truncated' => true])
        ->and($tree->page(['1', '6', '8'], 1))->toBe(['keys' => ['1', '6', '8'], 'truncated' => true])
        ->and($tree->page(['1'], 3))->toBe(['keys' => ['1', '2', '3'], 'truncated' => true])
        ->and($tree->page([], 3))->toBe(['keys' => [], 'truncated' => false]);
});

it('shows a branch of a record in a cycle without the record itself, its ancestor and duplicates', function (): void {
    $tree = new Tree(['1' => '2', '2' => '1', '3' => '2']);

    expect($tree->roots('2'))->toBe(['3'])
        ->and($tree->subtrees($tree->roots('2')))->toBe(['3'])
        ->and($tree->roots('1'))->toBe(['2']);
});

it('knows the record itself and its descendants', function (): void {
    $tree = new Tree(['1' => null, '2' => '1', '3' => '2', '4' => null]);

    expect($tree->isSelfOrDescendant('1', '1'))->toBeTrue()
        ->and($tree->isSelfOrDescendant('1', '3'))->toBeTrue()
        ->and($tree->isSelfOrDescendant('1', '4'))->toBeFalse()
        ->and($tree->isSelfOrDescendant('3', '1'))->toBeFalse();
});
