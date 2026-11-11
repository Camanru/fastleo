<?php

declare(strict_types=1);

use App\FastleoShop\Enums\ShopOrderStatus;
use App\FastleoShop\Exceptions\OrderLimitException;
use App\FastleoShop\Exceptions\OutOfStockException;
use App\FastleoShop\Exceptions\ProductUnavailableException;
use App\FastleoShop\Models\ShopCategory;
use App\FastleoShop\Models\ShopOrder;
use App\FastleoShop\Models\ShopProduct;
use App\FastleoShop\ShopOrderResource;
use Fastleo\Fastleo;
use Fastleo\Installer\Modules;
use Fastleo\Notifications\MessageNotification;
use Fastleo\Support\OperationCancelled;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Модуль «Интернет-магазин»: код копируется в сайт и поднимается по-настоящему (четыре таблицы, модели, разделы, действие).
 */
const SHOP_TABLES = ['shop_order_items', 'shop_orders', 'shop_products', 'shop_categories'];

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-shop-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\FastleoShop\\\\": "app/"}}}');
    sandboxWithPest($this->sandbox);
    $this->app->setBasePath($this->sandbox);

    foreach (SHOP_TABLES as $table) {
        Schema::dropIfExists($table);
    }

    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoShop']);
});

afterEach(function (): void {
    Model::shouldBeStrict(false);
    Model::clearBootedModels();
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Скопировать модуль в сайт и поднять его: миграция, классы (один раз на процесс), фабрики.
 */
function bootCopiedShop(object $test): void
{
    $test->artisan('fastleo:module', ['modules' => ['shop'], '--no-interaction' => true])->assertSuccessful();
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    $files = [
        'app/Enums/ShopOrderStatus' => 'App\\FastleoShop\\Enums\\ShopOrderStatus',
        'app/Exceptions/OutOfStockException' => 'App\\FastleoShop\\Exceptions\\OutOfStockException',
        'app/Exceptions/ProductUnavailableException' => 'App\\FastleoShop\\Exceptions\\ProductUnavailableException',
        'app/Exceptions/OrderLimitException' => 'App\\FastleoShop\\Exceptions\\OrderLimitException',
        'app/Models/ShopCategory' => 'App\\FastleoShop\\Models\\ShopCategory',
        'app/Models/ShopProduct' => 'App\\FastleoShop\\Models\\ShopProduct',
        'app/Models/ShopOrder' => 'App\\FastleoShop\\Models\\ShopOrder',
        'app/Models/ShopOrderItem' => 'App\\FastleoShop\\Models\\ShopOrderItem',
        'app/Fastleo/Actions/ChangeShopOrderStatus' => 'App\\FastleoShop\\Actions\\ChangeShopOrderStatus',
        'app/Fastleo/ShopCategoryResource' => 'App\\FastleoShop\\ShopCategoryResource',
        'app/Fastleo/ShopProductResource' => 'App\\FastleoShop\\ShopProductResource',
        'app/Fastleo/ShopOrderItemResource' => 'App\\FastleoShop\\ShopOrderItemResource',
        'app/Fastleo/ShopOrderResource' => 'App\\FastleoShop\\ShopOrderResource',
        'database/factories/ShopCategoryFactory' => 'Database\\Factories\\ShopCategoryFactory',
        'database/factories/ShopProductFactory' => 'Database\\Factories\\ShopProductFactory',
        'database/factories/ShopOrderFactory' => 'Database\\Factories\\ShopOrderFactory',
    ];

    foreach ($files as $file => $class) {
        if (! class_exists($class, false) && ! enum_exists($class, false)) {
            require $test->sandbox.'/'.$file.'.php';
        }
    }

    Fastleo::resources()->flush();
}

/**
 * Поднять и публичную часть магазина: контроллеры, корзину, запрос оформления, маршруты и шаблоны. Покупатель — с постоянной
 * сессией: метка формы привязана к её номеру.
 */
function bootCopiedShopSite(object $test): void
{
    bootCopiedShop($test);
    bootSiteRoutes($test, [
        'App\\FastleoShop\\Http\\Controllers\\ShopController' => 'app/Http/Controllers/ShopController.php',
        'App\\FastleoShop\\Http\\Controllers\\CartController' => 'app/Http/Controllers/CartController.php',
        'App\\FastleoShop\\Http\\Controllers\\CheckoutController' => 'app/Http/Controllers/CheckoutController.php',
        'App\\FastleoShop\\Http\\ShopCart' => 'app/Http/ShopCart.php',
        'App\\FastleoShop\\Http\\Requests\\CheckoutRequest' => 'app/Http/Requests/CheckoutRequest.php',
    ], ['routes/web.php']);

    $test->sessionId = Str::random(40);
    $test->withCookie(config('session.cookie'), $test->sessionId);
    Cache::flush();
    RateLimiter::clear('shop-orders-notify');
}

/**
 * Метка формы оформления, показанной покупателю `$secondsAgo` секунд назад.
 */
function shopToken(int $secondsAgo = 60, ?string $session = null): string
{
    return Crypt::encryptString((now()->timestamp - $secondsAgo).'|'.($session ?? test()->sessionId).'|'.Str::random(16));
}

/**
 * Данные формы оформления с верной меткой.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutData(array $overrides = []): array
{
    return [...['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_token' => shopToken()], ...$overrides];
}

/**
 * Корзина в сессии покупателя: номер товара → количество. Пишется в хранилище сессии (а не в память теста): следующий
 * запрос читает её как настоящую, в том числе второй после очистки корзины первым.
 *
 * @param  array<int, int>  $items
 */
function cartOf(array $items): void
{
    $session = resolve('session.store');
    $session->setId(test()->sessionId);
    $session->start();
    $session->put('fastleo.shop.cart', $items);
    $session->save();
}

/**
 * База — MySQL или MariaDB: тест, в котором `ShopOrder::place()` бросает исключение, там не воспроизвести.
 */
function mysqlLike(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

it('is in the list of modules with its four tables', function (): void {
    expect(Modules::find('shop')->tables)->toBe(['shop_categories', 'shop_products', 'shop_orders', 'shop_order_items']);
});

it('copies the shop with one migration that creates the tables in a working order', function (): void {
    bootCopiedShop($this);

    expect(glob($this->sandbox.'/database/migrations/*_create_shop_tables.php'))->toHaveCount(1)
        ->and(siteRoutesOf($this))->toContain('CheckoutController::class')
        ->and($this->sandbox.'/app/Http/ShopCart.php')->toBeFile()
        ->and($this->sandbox.'/resources/views/shop/thanks.blade.php')->toBeFile()
        ->and(File::exists($this->sandbox.'/database/seeders/ShopSeeder.php'))->toBeTrue();

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

    $this->artisan('fastleo:module', ['modules' => ['shop'], '--no-interaction' => true])->assertFailed();

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
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('keeps a category that still holds products or subcategories, so that hiding it cannot be undone by deleting it', function (): void {
    bootCopiedShop($this);
    $this->actingAs(admin(), Fastleo::GUARD);
    $hidden = ShopCategory::factory()->create(['is_published' => false]);
    $product = ShopProduct::factory()->create(['category_id' => $hidden->id]);
    $parent = ShopCategory::factory()->create();
    ShopCategory::factory()->create(['parent_id' => $parent->id]);
    $empty = ShopCategory::factory()->create();
    $resource = Fastleo::resources()->find('shop-categories');

    // Отказ проверяется прямым вызовом: на MySQL откат транзакции удаления в тесте невозможен (CREATE TABLE закрыл транзакцию теста).
    expect(fn () => $resource->beforeDelete($hidden))->toThrow(OperationCancelled::class, 'В категории есть товары или подкатегории: сначала перенесите или удалите их.')
        ->and(fn () => $resource->beforeDelete($parent))->toThrow(OperationCancelled::class)
        ->and(ShopProduct::query()->available()->whereKey($product->id)->exists())->toBeFalse();

    $this->delete(route('fastleo.resources.destroy', ['shop-categories', $empty->id]))->assertSessionHasNoErrors();

    expect(ShopCategory::query()->whereKey($empty->id)->exists())->toBeFalse();
});

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

it('hides the products of a hidden category, of the children of a hidden category and shows the products without a category', function (): void {
    bootCopiedShop($this);
    $open = ShopCategory::factory()->create();
    $hidden = ShopCategory::factory()->create(['is_published' => false]);
    $child = ShopCategory::factory()->create(['parent_id' => $hidden->id]);
    $grandchild = ShopCategory::factory()->create(['parent_id' => $child->id]);
    $inOpen = ShopProduct::factory()->create(['category_id' => $open->id]);
    $loose = ShopProduct::factory()->create(['category_id' => null]);
    ShopProduct::factory()->create(['category_id' => $hidden->id]);
    ShopProduct::factory()->create(['category_id' => $child->id]);
    ShopProduct::factory()->create(['category_id' => $grandchild->id]);
    ShopProduct::factory()->create(['category_id' => $open->id, 'is_published' => false]);

    expect(ShopProduct::query()->available()->pluck('id')->sort()->values()->all())->toBe([$inOpen->id, $loose->id])
        ->and(ShopCategory::hiddenIds())->toEqualCanonicalizing([$hidden->id, $child->id, $grandchild->id])
        ->and(ShopCategory::query()->visible()->pluck('id')->all())->toBe([$open->id]);
});

it('stops the check of a loop in the tree of categories', function (): void {
    bootCopiedShop($this);
    $first = ShopCategory::factory()->create();
    $second = ShopCategory::factory()->create(['parent_id' => $first->id]);
    $first->forceFill(['parent_id' => $second->id])->saveQuietly();

    expect(ShopCategory::hiddenIds())->toBe([]);
});

it('does not take a product of a hidden category into an order even when its number is known', function (): void {
    bootCopiedShop($this);
    $hidden = ShopCategory::factory()->create(['is_published' => false]);
    $product = ShopProduct::factory()->create(['category_id' => $hidden->id, 'stock' => 5]);

    try {
        ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 1]);
        $this->fail('Ожидалась ошибка.');
    } catch (ProductUnavailableException $e) {
        expect($e->productId)->toBe($product->id)
            ->and($e)->toBeInstanceOf(InvalidArgumentException::class);
    }

    expect($product->fresh()->stock)->toBe(5)
        ->and(ShopOrder::query()->count())->toBe(0);
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('refuses an order over the limits before anything reaches the database', function (): void {
    bootCopiedShop($this);
    $product = ShopProduct::factory()->create(['price' => '9999999999.99', 'stock' => null]);
    $cheap = ShopProduct::factory()->create(['price' => '1.00', 'stock' => null]);
    $customer = ['name' => 'Иван', 'phone' => '+7 900 000-00-00'];

    expect(fn () => ShopOrder::place($customer, [$cheap->id => ShopOrder::MAX_QUANTITY + 1]))->toThrow(OrderLimitException::class, 'Одного товара в заказе не больше 9999 шт.')
        ->and(fn () => ShopOrder::place($customer, [$cheap->id => PHP_INT_MAX]))->toThrow(OrderLimitException::class)
        ->and(fn () => ShopOrder::place($customer, [$product->id => 2]))->toThrow(OrderLimitException::class, 'Сумма заказа слишком велика.')
        ->and(fn () => ShopOrder::place($customer, [$product->id => 1, $cheap->id => 1]))->toThrow(OrderLimitException::class)
        ->and(fn () => ShopOrder::place($customer, array_fill(1, ShopOrder::MAX_LINES + 1, 1)))->toThrow(OrderLimitException::class, 'В заказе не больше 100 позиций.')
        ->and(ShopOrder::query()->count())->toBe(0);

    expect(ShopOrder::place($customer, [$product->id => 1])->total)->toBe('9999999999.99');
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('cuts the phone of a buyer to the length the panel field takes', function (): void {
    bootCopiedShop($this);
    $product = ShopProduct::factory()->create(['stock' => null]);

    $order = ShopOrder::place(['name' => 'Иван', 'phone' => str_repeat('9', 40)], [$product->id => 1]);

    expect(mb_strlen($order->customer_phone))->toBe(32);
});

it('tells the users of the panel about an order after the outer transaction is committed, and not more than twenty times a minute', function (): void {
    bootCopiedShop($this);
    Notification::fake();
    config(['fastleo.notifications.bell' => true]);
    $admin = admin();
    RateLimiter::clear('shop-orders-notify');
    $product = ShopProduct::factory()->create(['stock' => null]);

    DB::transaction(function () use ($product): void {
        ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 1]);

        Notification::assertNothingSent();
    });

    Notification::assertSentToTimes($admin, MessageNotification::class, 1);

    foreach (range(1, 25) as $_) {
        ShopOrder::place(['name' => 'Иван', 'phone' => '+7 900 000-00-00'], [$product->id => 1]);
    }

    Notification::assertSentToTimes($admin, MessageNotification::class, 20);
    expect(ShopOrder::query()->count())->toBe(26);
});

it('keeps the orders of the buyers away from the agents and from the files', function (): void {
    bootCopiedShop($this);

    expect(ShopOrderResource::isAvailableInMcp())->toBeFalse()
        ->and(ShopOrderResource::isExportable())->toBeFalse();
});

it('shows the catalog with the visible categories and the products that can be bought', function (): void {
    bootCopiedShopSite($this);
    Model::shouldBeStrict();
    $open = ShopCategory::factory()->create(['title' => 'Посуда', 'slug' => 'dishes']);
    $hidden = ShopCategory::factory()->create(['title' => 'Скрытая категория', 'slug' => 'secret', 'is_published' => false]);
    ShopProduct::factory()->create(['title' => 'Чайник', 'slug' => 'kettle', 'category_id' => $open->id, 'price' => '1500.00', 'stock' => 5]);
    ShopProduct::factory()->create(['title' => 'Нет в продаже', 'slug' => 'gone', 'stock' => 0]);
    ShopProduct::factory()->create(['title' => 'Товар скрытой категории', 'slug' => 'in-secret', 'category_id' => $hidden->id]);
    ShopProduct::factory()->create(['title' => 'Скрытый товар', 'slug' => 'hidden', 'is_published' => false]);

    $this->get('/shop')->assertOk()
        ->assertSee('Посуда')
        ->assertSee('Чайник')
        ->assertSee('1 500,00 ₽')
        ->assertSee('Нет в наличии')
        ->assertDontSee('Скрытая категория')
        ->assertDontSee('Товар скрытой категории')
        ->assertDontSee('Скрытый товар');
    $this->get('/shop/categories/dishes')->assertOk()->assertSee('Чайник')->assertDontSee('Нет в продаже');
});

it('answers 404 for a hidden category, a hidden product and an address that cannot be a slug', function (): void {
    bootCopiedShopSite($this);
    $hidden = ShopCategory::factory()->create(['slug' => 'secret', 'is_published' => false]);
    ShopCategory::factory()->create(['slug' => 'inner', 'parent_id' => $hidden->id]);
    ShopProduct::factory()->create(['slug' => 'in-secret', 'category_id' => $hidden->id]);
    ShopProduct::factory()->create(['slug' => 'hidden', 'is_published' => false]);

    foreach (['/shop/categories/secret', '/shop/categories/inner', '/shop/categories/none', '/shop/in-secret', '/shop/hidden', '/shop/none'] as $url) {
        $this->get($url)->assertNotFound();
    }

    // Адрес, который не может быть слагом, отвечает 404 без запроса к базе: иначе `%00` на PostgreSQL даёт ошибку 500.
    foreach (['/shop/Kettle', '/shop/a%00b', '/shop/categories/A%00'] as $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertNotFound();

        expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'shop_')))->toBeEmpty();
    }
});

it('shows a product with its details, escapes its fields and does not tell the stock', function (): void {
    bootCopiedShopSite($this);
    Model::shouldBeStrict();
    $category = ShopCategory::factory()->create(['title' => 'Посуда', 'slug' => 'dishes']);
    ShopProduct::factory()->create([
        'title' => '<script>alert(1)</script>', 'slug' => 'kettle', 'category_id' => $category->id, 'sku' => 'K-1', 'price' => '1500.00', 'stock' => 4242,
        'description' => '<p>Хороший чайник</p>', 'specs' => ['Объём <b>л</b>' => '1,7 <i>л</i>'], 'photos' => ['uploads/shop/one.jpg'],
    ]);

    $this->get('/shop/kettle')->assertOk()
        ->assertSee('Посуда')
        ->assertSee('Артикул: K-1')
        ->assertSee('<p>Хороший чайник</p>', false)
        ->assertSee('Объём &lt;b&gt;л&lt;/b&gt;', false)
        ->assertSee('1,7 &lt;i&gt;л&lt;/i&gt;', false)
        ->assertSee('uploads/shop/one.jpg', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('4242');
});

it('puts a product in the cart, adds up the quantity and shows the prices of the database', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['title' => 'Чайник', 'slug' => 'kettle', 'price' => '1500.00', 'stock' => null]);

    $this->post('/cart', ['product_id' => $product->id, 'quantity' => 2, 'price' => '1.00'])->assertRedirect(route('site.shop.cart'));
    $this->post('/cart', ['product_id' => $product->id])->assertRedirect(route('site.shop.cart'));

    $this->get('/cart')->assertOk()->assertSee('Чайник')->assertSee('4 500,00 ₽')->assertSessionHasNoErrors();
    expect(session('fastleo.shop.cart'))->toBe([$product->id => 3]);
});

it('does not take a product that is hidden, missing or out of stock into the cart and tells the buyer so', function (): void {
    bootCopiedShopSite($this);
    $hidden = ShopCategory::factory()->create(['is_published' => false]);
    $ids = [
        ShopProduct::factory()->create(['is_published' => false])->id,
        ShopProduct::factory()->create(['category_id' => $hidden->id])->id,
        ShopProduct::factory()->create(['stock' => 0])->id,
        999999,
    ];

    foreach ($ids as $id) {
        $this->from('/shop')->post('/cart', ['product_id' => $id])->assertRedirect('/shop')->assertSessionHasErrors(['cart' => 'Этого товара уже нет в наличии или он скрыт.']);
    }

    // Сообщение видно в каталоге, а не только на странице товара и в корзине.
    $this->from('/shop')->followingRedirects()->post('/cart', ['product_id' => $ids[0]])->assertSee('Этого товара уже нет в наличии или он скрыт.');

    expect(session('fastleo.shop.cart'))->toBeNull();
});

it('checks the numbers of the cart form', function (array $data): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => null]);

    $this->post('/cart', [...['product_id' => $product->id], ...$data])->assertSessionHasErrors();
})->with([
    'no product' => [['product_id' => '']],
    'text product' => [['product_id' => 'abc']],
    'array product' => [['product_id' => [1]]],
    'huge product' => [['product_id' => '99999999999999999999']],
    'zero quantity' => [['quantity' => 0]],
    'negative quantity' => [['quantity' => -3]],
    'too many' => [['quantity' => 100]],
    'text quantity' => [['quantity' => 'много']],
    'fraction' => [['quantity' => '1.5']],
]);

it('keeps the cart to fifty positions and ninety-nine pieces of a product', function (): void {
    bootCopiedShopSite($this);
    $products = ShopProduct::factory()->count(51)->create(['stock' => null]);

    foreach ($products->take(50) as $product) {
        $this->post('/cart', ['product_id' => $product->id])->assertRedirect(route('site.shop.cart'));
    }

    $this->post('/cart', ['product_id' => $products->last()->id])->assertSessionHasErrors('cart');
    $this->post('/cart', ['product_id' => $products->first()->id, 'quantity' => 99]);
    $this->post('/cart', ['product_id' => $products->first()->id, 'quantity' => 99]);

    expect(session('fastleo.shop.cart'))->toHaveCount(50)
        ->and(session('fastleo.shop.cart')[$products->first()->id])->toBe(99);
});

it('changes and removes the positions of the cart only with PATCH and DELETE', function (): void {
    bootCopiedShopSite($this);
    $kept = ShopProduct::factory()->create(['stock' => null]);
    $other = ShopProduct::factory()->create(['stock' => null]);
    cartOf([$kept->id => 2, $other->id => 1]);

    $this->patch('/cart/'.$kept->id, ['quantity' => 5])->assertRedirect(route('site.shop.cart'));
    $this->patch('/cart/'.$other->id, ['quantity' => 0])->assertRedirect(route('site.shop.cart'));
    $this->patch('/cart/'.$kept->id, ['quantity' => 100])->assertSessionHasErrors('quantity');
    $this->patch('/cart/9999', ['quantity' => 3])->assertRedirect(route('site.shop.cart'));

    expect(session('fastleo.shop.cart'))->toBe([$kept->id => 5]);

    $this->get('/cart/'.$kept->id)->assertStatus(405);
    $this->delete('/cart/'.$kept->id)->assertRedirect(route('site.shop.cart'));
    $this->get('/cart/abc')->assertNotFound();

    expect(session('fastleo.shop.cart'))->toBe([]);
});

it('takes out of the cart what is not for sale any more and cuts the quantity to the stock', function (): void {
    bootCopiedShopSite($this);
    $hiddenCategory = ShopCategory::factory()->create(['is_published' => false]);
    $few = ShopProduct::factory()->create(['title' => 'Последние', 'stock' => 2, 'price' => '10.00']);
    $gone = ShopProduct::factory()->create(['title' => 'Скрыт', 'is_published' => false]);
    $emptied = ShopProduct::factory()->create(['title' => 'Закончился', 'stock' => 0]);
    $inHidden = ShopProduct::factory()->create(['title' => 'В скрытой', 'category_id' => $hiddenCategory->id]);
    cartOf([$few->id => 9, $gone->id => 1, $emptied->id => 1, $inHidden->id => 1, 424242 => 1]);

    $this->get('/cart')->assertOk()
        ->assertSee('Последние')
        ->assertSee('20,00 ₽')
        ->assertSee('убраны из корзины')
        ->assertSee('Количество некоторых товаров уменьшено')
        ->assertDontSee('Скрыт')
        ->assertDontSee('Закончился')
        ->assertDontSee('В скрытой');

    expect(session('fastleo.shop.cart'))->toBe([$few->id => 2]);
});

it('ignores whatever else lies in the cart of the session', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => null, 'title' => 'Чайник']);
    $this->withSession(['fastleo.shop.cart' => ['abc' => 5, $product->id => 'много', 0 => 1, -4 => 2, 777 => -1, 888 => 5000]]);

    $this->get('/cart')->assertOk()->assertSee('Корзина пуста.');

    $this->withSession(['fastleo.shop.cart' => 'не массив']);

    $this->get('/cart')->assertOk()->assertSee('Корзина пуста.');
});

it('sends the buyer with an empty cart from the checkout back to the cart', function (): void {
    bootCopiedShopSite($this);

    $this->get('/checkout')->assertRedirect(route('site.shop.cart'));
    $this->post('/checkout', checkoutData())->assertRedirect(route('site.shop.cart'))->assertSessionHasErrors('cart');
});

it('places an order from the cart with the buyer, takes the stock and shows only the number on the thank you page', function (): void {
    bootCopiedShopSite($this);
    Notification::fake();
    config(['fastleo.notifications.bell' => true]);
    $admin = admin();
    $product = ShopProduct::factory()->create(['title' => 'Чайник', 'price' => '1500.00', 'stock' => 5]);
    cartOf([$product->id => 2]);

    $this->post('/checkout', checkoutData(['email' => 'ivan@example.com', 'address' => 'Тюмень, ул. Ленина, 1', 'delivery' => 'Курьер', 'comment' => 'Позвоните']))
        ->assertRedirect(route('site.shop.thanks'))
        ->assertSessionHasNoErrors();

    $order = ShopOrder::query()->sole();

    expect($order->customer_name)->toBe('Иван')
        ->and($order->customer_email)->toBe('ivan@example.com')
        ->and($order->total)->toBe('3000.00')
        ->and($order->items)->toHaveCount(1)
        ->and($product->fresh()->stock)->toBe(3)
        ->and(session('fastleo.shop.cart'))->toBeNull();

    $this->get('/checkout/thanks')->assertOk()->assertSee('Заказ №'.$order->id)->assertDontSee('Иван')->assertDontSee('Тюмень');
    $this->get('/checkout/thanks')->assertRedirect(route('site.shop.index'));

    Notification::assertSentTo($admin, MessageNotification::class, function (MessageNotification $notification) use ($admin, $order): bool {
        $message = $notification->toFastleo($admin)->toArray();

        return $message['title'] === 'Новый заказ №'.$order->id && $message['body'] === '3000.00 ₽' && ! str_contains((string) $message['body'], 'Иван');
    });
});

it('sends the visitor who did not just place an order from the thank you page to the catalog', function (): void {
    bootCopiedShopSite($this);

    $this->get('/checkout/thanks')->assertRedirect(route('site.shop.index'));
    $this->withSession(['fastleo.shop.order' => 'не номер'])->get('/checkout/thanks')->assertRedirect(route('site.shop.index'));
});

it('drops an order of a bot silently: a filled trap or a form that was never shown', function (array $overrides): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 5]);
    cartOf([$product->id => 1]);

    $this->post('/checkout', checkoutData($overrides))->assertRedirect(route('site.shop.index'))->assertSessionHasNoErrors();

    expect(ShopOrder::query()->count())->toBe(0)
        ->and($product->fresh()->stock)->toBe(5);
})->with([
    'trap filled' => [['leave_empty' => 'http://spam.example']],
    'no token' => [['form_token' => '']],
    'forged token' => [['form_token' => 'подделка']],
]);

it('shows an error and keeps the data when the form was sent too fast, too late or from another session', function (int $secondsAgo, ?string $session): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 5]);
    cartOf([$product->id => 1]);

    $this->post('/checkout', checkoutData(['form_token' => shopToken($secondsAgo, $session)]))->assertSessionHasErrors('form_token')->assertSessionHasInput('name', 'Иван');

    expect(ShopOrder::query()->count())->toBe(0);
})->with([
    'too fast' => [1, null],
    'too late' => [86401, null],
    'other session' => [60, 'другая-сессия'],
]);

it('lets one form place one order: a second send of the same form is refused even with a stale cart', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 10]);
    $token = shopToken();

    // Две отправки с одним и тем же состоянием сессии: так выглядят два параллельных запроса (оба прочитали полную корзину).
    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertSessionHasErrors('form_token');

    expect(ShopOrder::query()->count())->toBe(1)
        ->and($product->fresh()->stock)->toBe(9);
});

it('frees the token of the form when the order fails on something unexpected, so the same form can be sent again', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 10]);
    $token = shopToken();
    $this->withoutExceptionHandling();

    ShopOrder::creating(fn (): never => throw new RuntimeException('взаимная блокировка'));
    cartOf([$product->id => 1]);

    expect(fn () => $this->post('/checkout', checkoutData(['form_token' => $token])))->toThrow(RuntimeException::class, 'взаимная блокировка');

    ShopOrder::flushEventListeners();
    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->count())->toBe(1);
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('counts a form with a spoiled token as the same form', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 10]);
    $token = shopToken();

    // Laravel расшифровывает метку и с лишними символами в конце: ключ одноразовости не должен зависеть от строки метки.
    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => $token.'!!']))->assertSessionHasErrors('form_token');

    expect(ShopOrder::query()->count())->toBe(1)
        ->and($product->fresh()->stock)->toBe(9);
});

it('does not place an order whose content changed while the buyer filled the form', function (string $change): void {
    bootCopiedShopSite($this);
    $kept = ShopProduct::factory()->create(['stock' => 10]);
    $changed = ShopProduct::factory()->create(['stock' => 5]);
    cartOf([$kept->id => 1, $changed->id => 3]);

    match ($change) {
        'hidden' => $changed->update(['is_published' => false]),
        'short' => $changed->update(['stock' => 2]),
    };

    $this->post('/checkout', checkoutData(['form_token' => shopToken()]))
        ->assertRedirect(route('site.shop.checkout'))
        ->assertSessionHasErrors('cart');

    expect(ShopOrder::query()->count())->toBe(0)
        ->and($kept->fresh()->stock)->toBe(10)
        ->and($changed->fresh()->stock)->toBe($change === 'short' ? 2 : 5);

    // Покупатель увидел новое содержимое и отправил форму ещё раз: заказ оформляется.
    $this->post('/checkout', checkoutData(['form_token' => shopToken()]))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->count())->toBe(1);
})->with(['the product was hidden' => 'hidden', 'the stock got short' => 'short']);

it('does not place an order at a price the buyer did not see', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['price' => '100.00', 'stock' => 10]);
    cartOf([$product->id => 2]);

    $this->get('/checkout')->assertOk()->assertSee('name="shown_total" value="200.00"', false);

    $product->update(['price' => '5000.00']);

    $this->post('/checkout', checkoutData(['shown_total' => '200.00']))
        ->assertRedirect(route('site.shop.checkout'))
        ->assertSessionHasErrors('cart');

    expect(ShopOrder::query()->count())->toBe(0)
        ->and($product->fresh()->stock)->toBe(10);

    // Покупатель увидел новую сумму и отправил форму ещё раз: заказ оформляется по ней.
    $this->post('/checkout', checkoutData(['shown_total' => '10000.00']))->assertRedirect(route('site.shop.thanks'));

    expect((string) ShopOrder::query()->sole()->total)->toBe('10000.00');
});

it('refuses a phone without digits and takes a short local number', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 10]);
    cartOf([$product->id => 1]);

    foreach (['.....', '+-()', '12 34'] as $phone) {
        $this->post('/checkout', checkoutData(['phone' => $phone]))->assertSessionHasErrors('phone');
    }

    expect(ShopOrder::query()->count())->toBe(0);

    $this->post('/checkout', checkoutData(['phone' => '12-34-56']))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->count())->toBe(1);
});

it('measures the length of an email before it parses it', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 10]);
    cartOf([$product->id => 1]);

    $this->post('/checkout', checkoutData(['email' => str_repeat('a', 300)]))->assertSessionHasErrors('email');

    expect(session('errors')->get('email'))->toHaveCount(1)
        ->and(ShopOrder::query()->count())->toBe(0);
});

it('does not use up the form on a mistake of the buyer', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 10]);
    $token = shopToken();
    cartOf([$product->id => 1]);

    $this->post('/checkout', checkoutData(['form_token' => $token, 'phone' => 'телефон']))->assertSessionHasErrors('phone');
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->count())->toBe(1);
});

/**
 * Между проверкой корзины и созданием заказа что-то происходит (остаток упал, товар скрыли): при втором запросе товаров
 * (его делает `ShopOrder::place()`) выполняется `$sideEffect`. Так проверяется гонка, которую иначе не воспроизвести.
 */
function changeBeforeOrder(Closure $sideEffect): void
{
    $queries = 0;

    ShopProduct::addGlobalScope('race', function (Builder $query) use (&$queries, $sideEffect): void {
        if (++$queries === 2) {
            $sideEffect();
        }
    });
}

it('tells about a short stock when it ran out during the order and frees the form', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['title' => 'Редкий', 'stock' => 5]);
    $token = shopToken();
    cartOf([$product->id => 3]);
    changeBeforeOrder(fn () => DB::table('shop_products')->where('id', $product->id)->update(['stock' => 2]));

    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertSessionHasErrors(['cart' => '«Редкий»: в наличии 2 шт.']);

    // Заказ не создан и остаток не тронут (откат транзакции убрал и «упавший» остаток этой проверки).
    expect(ShopOrder::query()->count())->toBe(0)
        ->and(DB::table('shop_products')->where('id', $product->id)->value('stock'))->toBe(5);

    Model::clearBootedModels();
    cartOf([$product->id => 2]);
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->count())->toBe(1)
        ->and(DB::table('shop_products')->where('id', $product->id)->value('stock'))->toBe(3);
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('takes a product that was hidden during the order out of the cart, keeps the rest and frees the form', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 5]);
    $other = ShopProduct::factory()->create(['stock' => 5]);
    $token = shopToken();
    cartOf([$product->id => 1, $other->id => 1]);
    changeBeforeOrder(fn () => DB::table('shop_products')->where('id', $product->id)->update(['is_published' => false]));

    $this->post('/checkout', checkoutData(['form_token' => $token]))
        ->assertRedirect(route('site.shop.cart'))
        ->assertSessionHasErrors(['cart' => 'Один из товаров стал недоступен и убран из корзины.']);

    expect(session('fastleo.shop.cart'))->toBe([$other->id => 1])
        ->and(ShopOrder::query()->count())->toBe(0);

    Model::clearBootedModels();
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->sole()->items)->toHaveCount(1);
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('refuses an order over the limit with a message and frees the form', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['price' => '9999999999.99', 'stock' => null]);
    $token = shopToken();
    cartOf([$product->id => 2]);

    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertSessionHasErrors(['cart' => 'Сумма заказа слишком велика.']);

    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => $token]))->assertRedirect(route('site.shop.thanks'));

    expect(ShopOrder::query()->count())->toBe(1);
})->skip(fn (): bool => mysqlLike(), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста: откат к точке сохранения внутри place() невозможен (на сайте внешней транзакции нет)');

it('checks every field of the order form and never gives the database a byte it cannot take', function (string $field, mixed $value): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => 5]);
    cartOf([$product->id => 1]);

    $this->post('/checkout', checkoutData([$field => $value]))->assertSessionHasErrors($field);

    expect(ShopOrder::query()->count())->toBe(0);
})->with([
    'name array' => ['name', ['Иван']],
    'name zero byte' => ['name', "Ив\0ан"],
    'name too long' => ['name', str_repeat('я', 151)],
    'name missing' => ['name', ''],
    'phone letters' => ['phone', 'позвоните'],
    'phone too long' => ['phone', str_repeat('9', 33)],
    'email wrong' => ['email', 'не почта'],
    'email array' => ['email', ['a@b.c']],
    'address zero byte' => ['address', "Тю\0мень"],
    'address too long' => ['address', str_repeat('я', 1001)],
    'delivery zero byte' => ['delivery', "Ку\0рьер"],
    'comment zero byte' => ['comment', "Зво\0ните"],
    'comment too long' => ['comment', str_repeat('я', 2001)],
    'comment bad utf8' => ['comment', "\xC3\x28"],
]);

it('lets five orders from one address in ten minutes and no more', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => null]);
    RateLimiter::clear('shop-checkout'.sha1('|127.0.0.1'));

    foreach (range(1, 5) as $_) {
        cartOf([$product->id => 1]);
        $this->post('/checkout', checkoutData(['form_token' => shopToken()]))->assertRedirect(route('site.shop.thanks'));
    }

    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData(['form_token' => shopToken()]))->assertStatus(429);

    expect(ShopOrder::query()->count())->toBe(5);
});

it('keeps the cart limit apart from the checkout limit and from the limits of the other routes of the site', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => null]);

    foreach (range(1, 60) as $_) {
        $this->post('/cart', ['product_id' => $product->id])->assertRedirect(route('site.shop.cart'));
    }

    $this->post('/cart', ['product_id' => $product->id])->assertStatus(429);
    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData())->assertRedirect(route('site.shop.thanks'));
});

it('does not let a route of the site with the same throttle use up the checkout limit', function (): void {
    bootCopiedShopSite($this);
    Route::middleware(['web', 'throttle:5,10'])->get('/site-throttled', fn (): string => 'ok');
    $product = ShopProduct::factory()->create(['stock' => null]);

    foreach (range(1, 5) as $_) {
        $this->get('/site-throttled')->assertOk();
    }

    $this->get('/site-throttled')->assertStatus(429);
    cartOf([$product->id => 1]);
    $this->post('/checkout', checkoutData())->assertRedirect(route('site.shop.thanks'));
});

it('does not let a route of the site with the same throttle use up the cart limit', function (): void {
    bootCopiedShopSite($this);
    Route::middleware(['web', 'throttle:60,1'])->get('/site-throttled', fn (): string => 'ok');
    $product = ShopProduct::factory()->create(['stock' => null]);

    foreach (range(1, 60) as $_) {
        $this->get('/site-throttled')->assertOk();
    }

    $this->get('/site-throttled')->assertStatus(429);
    $this->post('/cart', ['product_id' => $product->id])->assertRedirect(route('site.shop.cart'));
});

it('prints the token of the forms: the product, the cart and the checkout', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['slug' => 'kettle', 'stock' => null]);
    cartOf([$product->id => 1]);

    foreach (['/shop', '/shop/kettle', '/cart', '/checkout'] as $url) {
        $this->get($url)->assertOk()->assertSee('name="_token"', false);
    }

    $this->get('/cart')->assertSee('name="_method" value="PATCH"', false)->assertSee('name="_method" value="DELETE"', false);
    $this->get('/checkout')->assertSee('name="form_token"', false)->assertSee('name="leave_empty"', false);
});

it('keeps the service pages away from the search engines', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['stock' => null]);
    cartOf([$product->id => 1]);

    foreach (['/cart', '/checkout'] as $url) {
        $this->get($url)->assertOk()->assertSee('<meta name="robots" content="noindex">', false);
    }

    $this->post('/checkout', checkoutData())->assertRedirect(route('site.shop.thanks'));
    $this->get('/checkout/thanks')->assertOk()->assertSee('<meta name="robots" content="noindex">', false);
});

it('escapes the title of a product in the cart and in the checkout', function (): void {
    bootCopiedShopSite($this);
    $product = ShopProduct::factory()->create(['title' => '<script>alert(1)</script>', 'stock' => null]);
    cartOf([$product->id => 1]);

    foreach (['/cart', '/checkout'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }
});

it('installs the shop without the public part when the site already has /cart', function (): void {
    Route::get('cart', fn (): string => 'своя корзина');

    $this->artisan('fastleo:module', ['modules' => ['shop']])
        ->expectsOutputToContain('Публичная часть модуля shop не добавлена')
        ->expectsOutputToContain('адрес /cart уже занят')
        ->assertSuccessful();

    expect($this->sandbox.'/app/Fastleo/ShopOrderResource.php')->toBeFile()
        ->and(siteRoutesOf($this))->not->toContain('ShopController::class');
});

it('tells how to run the shop safely only when the public part is written', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['shop'], '--no-interaction' => true])
        ->expectsOutputToContain('trustProxies')
        ->assertSuccessful();
});
