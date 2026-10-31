<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Models\TreeNode;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    createTreeNodesTable();
    useFixtureResources('TreeNodes');
});

it('does not delete a parent for good while its children sit in the trash', function (): void {
    $parent = TreeNode::query()->create(['title' => 'Родитель']);
    $child = TreeNode::query()->create(['title' => 'Вложенный', 'parent_id' => $parent->id]);
    $child->delete();
    $parent->delete();

    $this->delete(route('fastleo.resources.force-delete', ['tree-nodes', $parent->id]))
        ->assertSessionHas('fastleo.error', 'Сначала удалите или перенесите вложенные записи.');

    expect(TreeNode::withTrashed()->whereKey($parent->id)->exists())->toBeTrue();
});

it('still moves a parent whose children are only in the trash to the trash', function (): void {
    $parent = TreeNode::query()->create(['title' => 'Родитель']);
    TreeNode::query()->create(['title' => 'Вложенный', 'parent_id' => $parent->id])->delete();

    $this->delete(route('fastleo.resources.destroy', ['tree-nodes', $parent->id]))->assertSessionHas('fastleo.success');

    expect(TreeNode::onlyTrashed()->whereKey($parent->id)->exists())->toBeTrue();
});

it('finds a cycle when the parent is written in another letter case than the database stores it', function (): void {
    $parent = TreeNode::query()->create(['title' => 'Родитель']);
    $child = TreeNode::query()->create(['title' => 'Вложенный', 'parent_id' => $parent->id]);

    $this->put(route('fastleo.resources.update', ['tree-nodes', $parent->id]), ['title' => 'Родитель', 'parent_id' => strtoupper((string) $child->id)])
        ->assertSessionHasErrors('parent_id');

    expect($parent->fresh()?->parent_id)->toBeNull();
})->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL различает регистр строковых ключей');

it('puts a record restored from the trash at the end of its siblings', function (): void {
    $first = TreeNode::query()->create(['title' => 'А', 'sort' => 1]);
    TreeNode::query()->create(['title' => 'Б', 'sort' => 2]);
    $first->delete();

    $this->post(route('fastleo.resources.restore', ['tree-nodes', $first->id]))->assertSessionHas('fastleo.success');

    expect($first->fresh()?->sort)->toBe(3);
});

it('puts records restored by the bulk action at the end too', function (): void {
    $first = TreeNode::query()->create(['title' => 'А', 'sort' => 1]);
    TreeNode::query()->create(['title' => 'Б', 'sort' => 2]);
    $first->delete();

    $this->post(route('fastleo.resources.action', ['tree-nodes', 'restore']), ['ids' => [(string) $first->id]])->assertSessionHas('fastleo.success');

    expect($first->fresh()?->sort)->toBe(3);
});

it('answers 404 for a key that is not a uuid without asking the database', function (): void {
    DB::enableQueryLog();

    $this->get(route('fastleo.resources.edit', ['tree-nodes', 'not-a-uuid']))->assertNotFound();
    $this->get(route('fastleo.resources.index', ['resource' => 'tree-nodes', 'parent' => 'not-a-uuid']))->assertOk();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, '"id" = ?')))->toBeEmpty();
});

it('leaves the cycle check to the other rules when the database cannot look the parent key up', function (): void {
    $node = TreeNode::query()->create(['title' => 'Узел']);
    DB::beforeExecuting(function (string $query): void {
        if (preg_match('/^select\s+["`]?id["`]?\s+from/i', $query) === 1) {
            throw new QueryException(DB::getDefaultConnection(), $query, [], new PDOException('invalid input syntax'));
        }
    });

    $this->put(route('fastleo.resources.update', ['tree-nodes', $node->id]), ['title' => 'Узел', 'parent_id' => 'not-a-key'])
        ->assertSessionHasErrors('parent_id');

    expect($node->fresh()?->parent_id)->toBeNull();
});
