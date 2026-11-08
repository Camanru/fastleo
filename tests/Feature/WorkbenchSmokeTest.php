<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\ChoiceSample;
use Workbench\App\Models\Feedback;
use Workbench\App\Models\JsonSample;
use Workbench\App\Models\MediaSample;
use Workbench\App\Models\Page;
use Workbench\App\Models\Product;
use Workbench\App\Models\ProductReview;
use Workbench\App\Models\Supplier;
use Workbench\App\Models\Task;
use Workbench\App\Models\TextSample;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    $category = Category::factory()->create();
    Article::factory()->count(2)->create(['category_id' => $category->id]);
    $supplier = Supplier::factory()->create();
    Product::factory()->count(3)->create(['category_id' => $category->id, 'supplier_id' => $supplier->id])->each(fn (Product $product) => ProductReview::factory()->create(['product_id' => $product->id]));
    Topic::factory()->create();
    Feedback::factory()->create();
    Page::factory()->create();
    TextSample::factory()->create();
    ChoiceSample::factory()->create();
    MediaSample::factory()->create();
    JsonSample::factory()->create();
    Task::factory()->create(['supplier_id' => $supplier->id]);
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('opens every page of the demo site without errors', function (): void {
    $this->get(route('fastleo.dashboard'))->assertOk();

    foreach (array_keys(Fastleo::resources()->all()) as $slug) {
        $resource = Fastleo::resources()->find($slug);
        assert($resource instanceof \Fastleo\Resource);

        $this->get(route('fastleo.resources.index', $slug))->assertOk();
        $this->get(route('fastleo.resources.index', [$slug, 'q' => 'a', 'per_page' => 10]))->assertOk();

        if (! $resource->isReadonly()) {
            $this->get(route('fastleo.resources.create', $slug))->assertOk();
        }

        $record = $resource->newQuery()->first();

        if ($record === null) {
            continue;
        }

        $this->get(route('fastleo.resources.show', [$slug, $record->getKey()]))->assertOk();

        if (! $resource->isReadonly()) {
            $this->get(route('fastleo.resources.edit', [$slug, $record->getKey()]))->assertOk();
        }

        foreach ($resource->listRelations() as $related) {
            $this->get(route('fastleo.resources.related', [$slug, $record->getKey(), $related->name]))->assertOk();
        }
    }

    // Страница SoftNetica открывается только при включённом подключении к кабинету.
    foreach (array_diff(array_keys(Fastleo::resources()->pages()), ['softnetica']) as $page) {
        $this->get(route('fastleo.pages.show', $page))->assertOk();
    }

    foreach (array_keys(Fastleo::resources()->settings()) as $settings) {
        $this->get(route('fastleo.settings.edit', $settings))->assertOk();
    }
});

it('sorts and filters the product list and exports it', function (): void {
    $this->get(route('fastleo.resources.index', ['products', 'sort' => 'reviews_count', 'direction' => 'desc']))->assertOk();
    $this->get(route('fastleo.resources.index', ['products', 'filter' => ['price' => ['from' => '100'], 'status' => 'active']]))->assertOk();
    $this->get(route('fastleo.resources.export', ['products', 'format' => 'csv']))->assertOk();
});
