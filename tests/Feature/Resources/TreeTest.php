<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Дерево: Каталог → (Вентиляция → Осевые), Кондиционеры; отдельно — Новости.
 *
 * @return array<string, Category>
 */
function categoryTree(): array
{
    $catalog = Category::factory()->create(['title' => 'Каталог', 'sort' => 1]);
    $news = Category::factory()->create(['title' => 'Новости', 'sort' => 2]);
    $fans = Category::factory()->create(['title' => 'Вентиляция', 'parent_id' => $catalog->id, 'sort' => 1]);
    $axial = Category::factory()->create(['title' => 'Осевые', 'parent_id' => $fans->id, 'sort' => 1]);
    $air = Category::factory()->create(['title' => 'Кондиционеры', 'parent_id' => $catalog->id, 'sort' => 2]);

    return ['catalog' => $catalog, 'news' => $news, 'fans' => $fans, 'axial' => $axial, 'air' => $air];
}

describe('list', function (): void {
    it('shows the records as a tree with indents and child counts', function (): void {
        $tree = categoryTree();

        $this->get(route('fastleo.resources.index', 'categories'))
            ->assertOk()
            ->assertSeeInOrder(['Каталог', 'Вентиляция', 'Осевые', 'Кондиционеры', 'Новости'])
            ->assertSee('padding-left: 4rem', false)
            ->assertSee('data-ancestors="'.$tree['catalog']->id.' '.$tree['fans']->id.'"', false)
            ->assertSee('href="'.route('fastleo.resources.index', ['resource' => 'categories', 'parent' => $tree['catalog']->id]).'"', false)
            ->assertSee('data-tree-toggle', false)
            ->assertSee(route('fastleo.resources.create', ['resource' => 'categories', 'parent' => $tree['fans']->id]), false);
    });

    it('shows one branch', function (): void {
        $tree = categoryTree();

        $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'parent' => $tree['catalog']->id]))
            ->assertOk()
            ->assertSee('Ветка: Каталог')
            ->assertSeeInOrder(['Вентиляция', 'Осевые', 'Кондиционеры'])
            ->assertDontSee('Новости')
            ->assertSee(route('fastleo.resources.create', ['resource' => 'categories', 'parent' => $tree['catalog']->id]), false);
    });

    it('ignores an unknown branch and shows a flat list while searching', function (): void {
        categoryTree();

        $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'parent' => '999']))->assertOk()->assertDontSee('Ветка:');
        $this->get(route('fastleo.resources.index', ['resource' => 'categories', 'q' => 'Осев']))
            ->assertOk()
            ->assertSee('Осевые')
            ->assertDontSee('data-ancestors', false);
    });

    it('splits the tree into pages in tree order', function (): void {
        config(['fastleo.list.per_page' => 2]);
        categoryTree();

        expect($this->get(route('fastleo.resources.index', ['resource' => 'categories', 'page' => 2]))->viewData('records')->pluck('title')->all())
            ->toBe(['Осевые', 'Кондиционеры']);
    });
});

describe('form', function (): void {
    it('preselects the parent for a child and lists parents with indents', function (): void {
        $tree = categoryTree();

        $this->get(route('fastleo.resources.create', ['resource' => 'categories', 'parent' => $tree['fans']->id]))
            ->assertOk()
            ->assertSee(Js::from([
                'multiple' => false,
                'options' => [
                    ['value' => (string) $tree['catalog']->id, 'label' => 'Каталог'],
                    ['value' => (string) $tree['fans']->id, 'label' => '— Вентиляция'],
                    ['value' => (string) $tree['axial']->id, 'label' => '— — Осевые'],
                    ['value' => (string) $tree['air']->id, 'label' => '— Кондиционеры'],
                    ['value' => (string) $tree['news']->id, 'label' => 'Новости'],
                ],
                'selected' => [['value' => (string) $tree['fans']->id, 'label' => 'Вентиляция']],
                'url' => null,
                'remote' => false,
                'depends' => null,
                'record' => null,
            ])->toHtml(), false);
    });

    it('puts a new record and a moved record at the end of their branch', function (): void {
        $tree = categoryTree();

        $this->post(route('fastleo.resources.store', 'categories'), ['title' => 'Канальные', 'parent_id' => (string) $tree['fans']->id])->assertSessionHasNoErrors();
        expect(Category::query()->where('title', 'Канальные')->value('sort'))->toBe(2);

        $this->put(route('fastleo.resources.update', ['categories', $tree['news']->id]), ['title' => 'Новости', 'parent_id' => (string) $tree['catalog']->id])->assertSessionHasNoErrors();
        expect($tree['news']->fresh()?->sort)->toBe(3);

        $this->put(route('fastleo.resources.update', ['categories', $tree['air']->id]), ['title' => 'Кондиционеры (изм.)', 'parent_id' => (string) $tree['catalog']->id]);
        expect($tree['air']->fresh()?->sort)->toBe(2);
    });

    it('does not move a record inside itself', function (string $target): void {
        $tree = categoryTree();

        $this->put(route('fastleo.resources.update', ['categories', $tree['catalog']->id]), ['title' => 'Каталог', 'parent_id' => (string) $tree[$target]->id])
            ->assertSessionHasErrors(['parent_id' => 'Нельзя перенести запись внутрь неё самой.']);

        expect($tree['catalog']->fresh()?->parent_id)->toBeNull();
    })->with(['itself' => ['catalog'], 'child' => ['fans'], 'grandchild' => ['axial']]);

    it('does not let a signed number slip a cycle through', function (string $target): void {
        $tree = categoryTree();

        $this->put(route('fastleo.resources.update', ['categories', $tree['catalog']->id]), ['title' => 'Каталог', 'parent_id' => '+'.$tree[$target]->id])
            ->assertSessionHasErrors('parent_id');

        expect($tree['catalog']->fresh()?->parent_id)->toBeNull();
    })->with(['itself' => ['catalog'], 'child' => ['fans']]);

    it('sees nodes hidden by a global scope when checking for a cycle', function (): void {
        $tree = categoryTree();
        Category::addGlobalScope('hide-middle', fn (Builder $query) => $query->whereKeyNot($tree['fans']->id));

        try {
            $this->put(route('fastleo.resources.update', ['categories', $tree['catalog']->id]), ['title' => 'Каталог', 'parent_id' => (string) $tree['axial']->id])
                ->assertSessionHasErrors('parent_id');
        } finally {
            Category::clearBootedModels();
        }

        expect($tree['catalog']->fresh()?->parent_id)->toBeNull();
    });
});

it('does not delete a node that has children', function (): void {
    $tree = categoryTree();

    $this->get(route('fastleo.resources.index', 'categories'))
        ->assertDontSee('action="'.route('fastleo.resources.destroy', ['categories', $tree['catalog']->id]).'"', false)
        ->assertSee('action="'.route('fastleo.resources.destroy', ['categories', $tree['axial']->id]).'"', false);

    $this->delete(route('fastleo.resources.destroy', ['categories', $tree['catalog']->id]))
        ->assertSessionHas('fastleo.error', 'Сначала удалите или перенесите вложенные записи.');
    expect($tree['catalog']->fresh())->not->toBeNull();

    $this->delete(route('fastleo.resources.destroy', ['categories', $tree['axial']->id]));
    expect($tree['axial']->fresh())->toBeNull();
});
