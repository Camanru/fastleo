<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\FormSchema;
use Fastleo\Tests\Fixtures\NestedSelect\ProductResource as NestedSelectProductResource;
use Fastleo\Tests\Fixtures\NestedSelectLocked\ProductResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Js;
use Workbench\App\Models\Product;
use Workbench\App\Models\ProductBundleItem;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Данные формы товара.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bundleForm(array $overrides = []): array
{
    return ['title' => 'Набор', 'status' => 'active', 'price' => '0', 'stock' => '0', ...$overrides];
}

/**
 * Строки состава комплекта: [ключ товара, количество] по порядку.
 *
 * @return list<array{0: int|null, 1: int}>
 */
function bundleRows(Product $product): array
{
    return $product->bundleItems()->get()->map(fn ($row): array => [$row->item_id, $row->qty])->all();
}

describe('choosing a record in a block', function (): void {
    it('saves the chosen products in the order of the blocks', function (): void {
        [$first, $second] = Product::factory()->count(2)->create()->all();
        $kit = Product::factory()->create();

        $this->put(route('fastleo.resources.update', ['products', $kit->id]), bundleForm(['bundleItems' => [
            '0' => ['item_id' => (string) $second->id, 'qty' => '3'],
            '1' => ['item_id' => (string) $first->id, 'qty' => '1'],
        ]]))->assertSessionHasNoErrors();

        expect(bundleRows($kit))->toBe([[$second->id, 3], [$first->id, 1]]);
    });

    it('asks to choose a product when the field is required', function (): void {
        $kit = Product::factory()->create();

        $this->put(route('fastleo.resources.update', ['products', $kit->id]), bundleForm(['bundleItems' => ['0' => ['item_id' => '', 'qty' => '2']]]))
            ->assertSessionHasErrors('bundleItems.0.item_id');

        expect(bundleRows($kit))->toBe([]);
    });

    it('refuses a product that does not exist', function (): void {
        $kit = Product::factory()->create();

        $this->put(route('fastleo.resources.update', ['products', $kit->id]), bundleForm(['bundleItems' => ['0' => ['item_id' => '99999', 'qty' => '1']]]))
            ->assertSessionHasErrors('bundleItems.0.item_id');

        expect(bundleRows($kit))->toBe([]);
    });

    it('shows the chosen product with its title, asks the server for variants and has a template for new blocks', function (): void {
        $item = Product::factory()->create(['title' => 'Лампа']);
        $kit = Product::factory()->create();
        $kit->bundleItems()->create(['item_id' => $item->id, 'qty' => 2, 'sort' => 1]);

        $this->get(route('fastleo.resources.edit', ['products', $kit->id]))
            ->assertOk()
            ->assertSee(Js::from([
                'multiple' => false,
                'options' => [],
                'selected' => [['value' => (string) $item->id, 'label' => 'Лампа']],
                'url' => route('fastleo.resources.options', ['products', 'bundleItems.item_id']),
                'remote' => true,
                'depends' => null,
                'record' => null,
            ])->toHtml(), false)
            ->assertSee('name="bundleItems[__KEY__][item_id]"', false);
    });

    it('takes the titles of all blocks with one query', function (): void {
        $kit = Product::factory()->create();
        $items = Product::factory()->count(4)->create();
        $items->each(fn (Product $item, int $index) => $kit->bundleItems()->create(['item_id' => $item->id, 'qty' => 1, 'sort' => $index]));
        $lookups = function (string $route) use ($kit): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route($route, ['products', $kit->id]))->assertOk();

            return collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from [`"]products[`"] where [`"]products[`"]\.[`"]id[`"] in/', $query['query']) === 1)->count();
        };

        expect($lookups('fastleo.resources.edit'))->toBe(1)->and($lookups('fastleo.resources.show'))->toBe(1);
    });

    it('shows the title of the chosen product on the record page', function (): void {
        $item = Product::factory()->create(['title' => 'Розетка']);
        $kit = Product::factory()->create();
        $kit->bundleItems()->create(['item_id' => $item->id, 'qty' => 1, 'sort' => 1]);

        $this->get(route('fastleo.resources.show', ['products', $kit->id]))->assertOk()->assertSee('Розетка');
    });

    it('keeps the chosen product after a validation error', function (): void {
        $item = Product::factory()->create(['title' => 'Лампа']);
        $kit = Product::factory()->create();

        $this->from(route('fastleo.resources.edit', ['products', $kit->id]))
            ->put(route('fastleo.resources.update', ['products', $kit->id]), bundleForm(['title' => '', 'bundleItems' => ['0' => ['item_id' => (string) $item->id, 'qty' => '1']]]))
            ->assertSessionHasErrors('title');

        $this->get(route('fastleo.resources.edit', ['products', $kit->id]))->assertOk()->assertSee('Лампа');
    });
});

describe('search of a block field', function (): void {
    it('finds products by title', function (): void {
        Product::factory()->create(['title' => 'Кабель медный']);
        Product::factory()->create(['title' => 'Розетка']);

        $labels = collect($this->getJson(route('fastleo.resources.options', ['products', 'bundleItems.item_id', 'q' => 'Кабель']))->json('options'))->pluck('label');

        expect($labels->all())->toBe(['Кабель медный']);
    });

    it('does not answer for a field that is not a list or not in a block', function (string $field): void {
        $this->getJson(route('fastleo.resources.options', ['products', $field]))->assertNotFound();
    })->with([
        'a block field without options' => 'bundleItems.qty',
        'a field that is not in the block' => 'bundleItems.nothing',
        'a field that holds no blocks' => 'title.item_id',
        'no such field' => 'ghost.item_id',
    ]);

    it('refuses a field of the block named like the foreign key of the relation', function (): void {
        useFixtureResources('NestedSelectLocked');
        ProductResource::$locked = 'foreign';
        Fastleo::resources()->flush();

        expect(fn (): FormSchema => Fastleo::resources()->find('products')->schema()->field('bundleItems')?->schema())->toThrow(LogicException::class, 'внешним ключом');
    });

    it('does not answer for a field that the admin cannot change', function (string $locked): void {
        useFixtureResources('NestedSelectLocked');
        ProductResource::$locked = $locked;
        Fastleo::resources()->flush();

        $this->getJson(route('fastleo.resources.options', ['products', 'bundleItems.item_id']))->assertNotFound();
    })->with(['the whole block' => 'block', 'the field of the block' => 'field']);
});

describe('the variants of a block field', function (): void {
    beforeEach(function (): void {
        NestedSelectProductResource::$distinct = false;
        useFixtureResources('NestedSelect');
        $this->kit = Product::factory()->create(['status' => 'active']);
    });

    it('offers only the products allowed by the field query', function (): void {
        Product::factory()->create(['title' => 'В продаже', 'status' => 'active']);
        Product::factory()->create(['title' => 'Черновик', 'status' => 'draft']);

        $labels = collect($this->getJson(route('fastleo.resources.options', ['products', 'bundleItems.item_id']))->json('options'))->pluck('label');

        expect($labels->all())->toContain('В продаже')->not->toContain('Черновик');
    });

    it('refuses a product outside the field query sent by hand', function (): void {
        $draft = Product::factory()->create(['status' => 'draft']);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => ['0' => ['item_id' => (string) $draft->id, 'qty' => '1']]])
            ->assertSessionHasErrors('bundleItems.0.item_id');

        expect(bundleRows($this->kit))->toBe([]);
    });

    it('keeps a stored product that has left the variants, but not a new one like it', function (): void {
        $stored = Product::factory()->create(['status' => 'draft']);
        $other = Product::factory()->create(['status' => 'draft']);
        $row = $this->kit->bundleItems()->create(['item_id' => $stored->id, 'qty' => 1, 'sort' => 1]);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => ['0' => ['id' => (string) $row->id, 'item_id' => (string) $stored->id, 'qty' => '5']]])
            ->assertSessionHasNoErrors();

        expect(bundleRows($this->kit))->toBe([[$stored->id, 5]]);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => ['0' => ['id' => (string) $row->id, 'item_id' => (string) $other->id, 'qty' => '5']]])
            ->assertSessionHasErrors('bundleItems.0.item_id');

        expect(bundleRows($this->kit))->toBe([[$stored->id, 5]]);
    });

    it('does not let a new block pick the product that is stored in another block', function (): void {
        $stored = Product::factory()->create(['status' => 'draft']);
        $row = $this->kit->bundleItems()->create(['item_id' => $stored->id, 'qty' => 1, 'sort' => 1]);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => [
            '0' => ['id' => (string) $row->id, 'item_id' => (string) $stored->id, 'qty' => '1'],
            '1' => ['item_id' => (string) $stored->id, 'qty' => '2'],
        ]])->assertSessionHasErrors('bundleItems.1.item_id');

        expect(bundleRows($this->kit))->toBe([[$stored->id, 1]]);
    });

    it('refuses the same product in two blocks when the field asks for distinct values', function (): void {
        NestedSelectProductResource::$distinct = true;
        $item = Product::factory()->create(['status' => 'active']);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => [
            '0' => ['item_id' => (string) $item->id, 'qty' => '1'],
            '1' => ['item_id' => (string) $item->id, 'qty' => '2'],
        ]])->assertSessionHasErrors(['bundleItems.0.item_id', 'bundleItems.1.item_id']);

        NestedSelectProductResource::$distinct = false;
        expect(bundleRows($this->kit))->toBe([]);
    });

    it('checks the products of all blocks with one query', function (): void {
        $items = Product::factory()->count(3)->create(['status' => 'active']);
        DB::enableQueryLog();

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => $items->map(fn (Product $item): array => ['item_id' => (string) $item->id, 'qty' => '1'])->all()])
            ->assertSessionHasNoErrors();

        $checks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'status') && str_starts_with($query['query'], 'select'));

        expect($checks)->toHaveCount(1)->and(bundleRows($this->kit))->toHaveCount(3);
    });

    it('removes the blocks that are gone before it writes the rest', function (): void {
        $item = Product::factory()->create(['status' => 'active']);
        $row = $this->kit->bundleItems()->create(['item_id' => $item->id, 'qty' => 1, 'sort' => 1]);
        $steps = [];
        Event::listen('eloquent.deleted: '.ProductBundleItem::class, function () use (&$steps): void {
            $steps[] = 'deleted';
        });
        Event::listen('eloquent.created: '.ProductBundleItem::class, function () use (&$steps): void {
            $steps[] = 'created';
        });

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => ['0' => ['item_id' => (string) $item->id, 'qty' => '4']]])
            ->assertSessionHasNoErrors();

        expect($steps)->toBe(['deleted', 'created'])
            ->and($row->fresh())->toBeNull()
            ->and(bundleRows($this->kit))->toBe([[$item->id, 4]]);
    });

    it('does not take a block of another product by its id', function (): void {
        $stranger = Product::factory()->create();
        $foreign = $stranger->bundleItems()->create(['item_id' => Product::factory()->create()->id, 'qty' => 1, 'sort' => 1]);
        $item = Product::factory()->create(['status' => 'active']);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => ['0' => ['id' => (string) $foreign->id, 'item_id' => (string) $item->id, 'qty' => '9']]])
            ->assertSessionHasNoErrors();

        expect(bundleRows($stranger))->toHaveCount(1)->and($foreign->fresh()?->qty)->toBe(1)->and(bundleRows($this->kit))->toBe([]);
    });

    it('does not create a block in which nothing was chosen', function (): void {
        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'bundleItems' => ['0' => ['item_id' => '', 'qty' => '1']]])
            ->assertSessionHasNoErrors();

        expect(bundleRows($this->kit))->toBe([]);
    });

    it('does not take product ids sent in a field outside the list of blocks', function (): void {
        $draft = Product::factory()->create(['status' => 'draft']);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'item_id' => (string) $draft->id])
            ->assertSessionHasNoErrors();

        expect(bundleRows($this->kit))->toBe([]);
    });
});

describe('choosing a record in a single block', function (): void {
    beforeEach(function (): void {
        useFixtureResources('NestedSelectSingle');
        $this->kit = Product::factory()->create(['status' => 'active']);
    });

    it('keeps the stored product that has left the variants, but not a new one like it', function (): void {
        $stored = Product::factory()->create(['status' => 'draft']);
        $other = Product::factory()->create(['status' => 'draft']);
        $this->kit->companion()->create(['item_id' => $stored->id, 'qty' => 1, 'sort' => 1]);

        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'companion' => ['item_id' => (string) $stored->id]])
            ->assertSessionHasNoErrors();
        $this->put(route('fastleo.resources.update', ['products', $this->kit->id]), ['title' => 'Набор', 'companion' => ['item_id' => (string) $other->id]])
            ->assertSessionHasErrors('companion.item_id');

        expect(bundleRows($this->kit))->toBe([[$stored->id, 1]]);
    });
});
