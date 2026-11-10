<?php

declare(strict_types=1);

use App\FastleoShop\Enums\ShopOrderStatus;
use App\FastleoShop\Exceptions\OutOfStockException;
use App\FastleoShop\Models\ShopCategory;
use App\FastleoShop\Models\ShopOrder;
use App\FastleoShop\Models\ShopProduct;
use Fastleo\Fastleo;
use Fastleo\Installer\Modules;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Модуль «Интернет-магазин»: код копируется в сайт и поднимается по-настоящему (четыре таблицы, модели, разделы, действие).
 */
const SHOP_TABLES = ['shop_order_items', 'shop_orders', 'shop_products', 'shop_categories'];

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-shop-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($this->sandbox);

    foreach (SHOP_TABLES as $table) {
        Schema::dropIfExists($table);
    }

    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoShop']);
});

afterEach(function (): void {
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Скопировать модуль в сайт и поднять его: миграция, классы (один раз на процесс), фабрики.
 */
function bootCopiedShop(object $test): void
{
    $test->artisan('fastleo:module', ['modules' => ['shop']])->assertSuccessful();
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    $files = [
        'app/Fastleo/Enums/ShopOrderStatus' => 'App\\FastleoShop\\Enums\\ShopOrderStatus',
        'app/Fastleo/Exceptions/OutOfStockException' => 'App\\FastleoShop\\Exceptions\\OutOfStockException',
        'app/Fastleo/Models/ShopCategory' => 'App\\FastleoShop\\Models\\ShopCategory',
        'app/Fastleo/Models/ShopProduct' => 'App\\FastleoShop\\Models\\ShopProduct',
        'app/Fastleo/Models/ShopOrder' => 'App\\FastleoShop\\Models\\ShopOrder',
        'app/Fastleo/Models/ShopOrderItem' => 'App\\FastleoShop\\Models\\ShopOrderItem',
        'app/Fastleo/Actions/ChangeShopOrderStatus' => 'App\\FastleoShop\\Actions\\ChangeShopOrderStatus',
        'app/Fastleo/ShopCategoryResource' => 'App\\FastleoShop\\ShopCategoryResource',
        'app/Fastleo/ShopProductResource' => 'App\\FastleoShop\\ShopProductResource',
        'app/Fastleo/ShopOrderItemResource' => 'App\\FastleoShop\\ShopOrderItemResource',
        'app/Fastleo/ShopOrderResource' => 'App\\FastleoShop\\ShopOrderResource',
        'database/factories/Fastleo/ShopCategoryFactory' => 'Database\\Factories\\Fastleo\\ShopCategoryFactory',
        'database/factories/Fastleo/ShopProductFactory' => 'Database\\Factories\\Fastleo\\ShopProductFactory',
        'database/factories/Fastleo/ShopOrderFactory' => 'Database\\Factories\\Fastleo\\ShopOrderFactory',
    ];

    foreach ($files as $file => $class) {
        if (! class_exists($class, false) && ! enum_exists($class, false)) {
            require $test->sandbox.'/'.$file.'.php';
        }
    }

    Fastleo::resources()->flush();
}

it('is in the list of modules with its four tables', function (): void {
    expect(Modules::find('shop')->tables)->toBe(['shop_categories', 'shop_products', 'shop_orders', 'shop_order_items']);
});

it('copies the shop with one migration that creates the tables in a working order', function (): void {
    bootCopiedShop($this);

    expect(glob($this->sandbox.'/database/migrations/*_create_shop_tables.php'))->toHaveCount(1)
        ->and($this->sandbox.'/routes/site')->not->toBeDirectory()
        ->and(File::exists($this->sandbox.'/database/seeders/Fastleo/ShopSeeder.php'))->toBeTrue();

    foreach (SHOP_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    $this->artisan('migrate:rollback', ['--path' => $this->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    expect(Schema::hasTable('shop_orders'))->toBeFalse();
});

it('does not install the shop over an existing table of the site', function (string $table): void {
    Schema::create($table, function ($table): void {
        $table->id();
    });

    $this->artisan('fastleo:module', ['modules' => ['shop']])->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
})->with(['shop_products', 'shop_orders']);

it('places an order with the prices of the database and writes the stock off', function (): void {
    bootCopiedShop($this);
    $product = ShopProduct::factory()->create(['price' => '10.10', 'stock' => 5]);

    $order = ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 3]);

    expect($order->total)->toBe('30.30')
        ->and($product->fresh()->stock)->toBe(2)
        ->and($order->changeStatus(ShopOrderStatus::Cancelled))->toBeTrue()
        ->and($product->fresh()->stock)->toBe(5);
});

it('gives back on cancel only the stock the order took', function (): void {
    bootCopiedShop($this);
    $kept = ShopProduct::factory()->create(['stock' => 5]);
    $free = ShopProduct::factory()->create(['stock' => null]);
    $order = ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$free->id => 2, $kept->id => 1]);
    // Учёт остатка у товара включили уже после заказа.
    $free->update(['stock' => 10]);

    expect($order->changeStatus(ShopOrderStatus::Cancelled))->toBeTrue()
        ->and($kept->fresh()->stock)->toBe(5)
        ->and($free->fresh()->stock)->toBe(10);
});

it('writes the new status even when the order object holds an old one', function (): void {
    bootCopiedShop($this);
    $product = ShopProduct::factory()->create(['stock' => 5]);
    $order = ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 1]);
    // Другой менеджер успел отправить заказ, а у этого объекта статус ещё «Новый».
    ShopOrder::query()->whereKey($order->id)->update(['status' => ShopOrderStatus::Shipped]);

    expect($order->changeStatus(ShopOrderStatus::New))->toBeTrue()
        ->and($order->fresh()->status)->toBe(ShopOrderStatus::New);
});

it('refuses an order when the stock is short and writes nothing off', function (): void {
    bootCopiedShop($this);
    $product = ShopProduct::factory()->create(['price' => '10.10', 'stock' => 2]);

    expect(fn () => ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 3]))->toThrow(OutOfStockException::class)
        ->and($product->fresh()->stock)->toBe(2)
        ->and(ShopOrder::query()->count())->toBe(0);
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('gives the administrator working shop sections in one group that pass fastleo:doctor', function (): void {
    bootCopiedShop($this);
    $this->actingAs(admin(), Fastleo::GUARD);
    $category = ShopCategory::factory()->create();
    $product = ShopProduct::factory()->create(['category_id' => $category->id, 'stock' => 2]);
    $order = ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 1]);

    foreach (['shop-categories', 'shop-products', 'shop-orders'] as $slug) {
        $this->get(route('fastleo.resources.index', $slug))->assertOk();
    }

    $this->get(route('fastleo.resources.edit', ['shop-orders', $order->id]))->assertOk()->assertSee('Заметка менеджера');
    $this->get(route('fastleo.resources.create', 'shop-orders'))->assertForbidden();
    $this->post(route('fastleo.resources.store', 'shop-products'), ['title' => 'Чайник', 'slug' => 'chajnik', 'price' => '99.90', 'is_published' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('fastleo.resources.action', ['shop-orders', 'change-shop-order-status']), ['ids' => [$order->id], 'data' => ['status' => 'cancelled']])->assertSessionHas('fastleo.success');

    expect(ShopProduct::query()->where('slug', 'chajnik')->exists())->toBeTrue()
        ->and($order->fresh()->status)->toBe(ShopOrderStatus::Cancelled)
        ->and($product->fresh()->stock)->toBe(2)
        ->and(Fastleo::resources()->find('shop-orders')::group())->toBe('Магазин')
        ->and(Fastleo::resources()->find('shop-products')::group())->toBe('Магазин')
        ->and(Fastleo::resources()->find('shop-categories')::group())->toBe('Магазин')
        ->and(Fastleo::resources()->find('shop-orders')->relations())->toHaveCount(1);

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('level', 'error')->whereIn('scope', ['shop-categories', 'shop-products', 'shop-orders', 'shop-order-items']);

    expect($problems->all())->toBe([]);
});
