# Тесты разделов в сайте

Раздел админки — обычные маршруты Laravel, поэтому его тестируют обычными Feature-тестами сайта: войти администратором, открыть страницу, отправить форму, проверить базу. Своих хелперов у пакета нет — хватает стандартных `actingAs()`, `get()`, `post()`.

## Администратор в тесте

Администратор — пользователь, у которого `canAccessFastleo()` возвращает `true` (по умолчанию — колонка `fastleo_admin`). Колонки `fastleo_admin` нет в `$fillable`, поэтому ставьте её через `forceFill()` или состояние фабрики. Состояния `admin()` в новом сайте нет (его не создаёт `fastleo:install`): добавьте его в `UserFactory` или пишите `$admin = tap(User::factory()->create(), fn (User $user) => $user->forceFill(['fastleo_admin' => true])->save());` (`tap` с функцией возвращает пользователя, а не результат `save()`):

```php
// database/factories/UserFactory.php
public function admin(): static
{
    return $this->state(['fastleo_admin' => true]);
}
```

Вход — guard `fastleo`:

```php
$admin = User::factory()->admin()->create();

$this->actingAs($admin, 'fastleo');
```

`actingAs($admin)` без второго аргумента входит на сайт, а не в админку — страницы админки ответят переходом на форму входа.

## Сброс пароля в тестах

Запрос ссылки и ввод нового пароля отвечают не быстрее секунды (так по скорости ответа не узнать, есть ли такой администратор). В тесте сайта, который ходит на эти маршруты, подмените паузу, иначе каждый запрос займёт секунду:

```php
use Illuminate\Support\Sleep;

Sleep::fake();

$this->post(route('fastleo.password.email'), ['email' => 'admin@example.com']);
```

## Пример

Тестам нужна чистая база с таблицами: в Pest — `->use(RefreshDatabase::class)` в `tests/Pest.php` (в новом Laravel эта строка закомментирована) или `uses(RefreshDatabase::class);` в файле теста, в PHPUnit — `use RefreshDatabase;` в классе. Без этого тесты на SQLite в памяти падают с «no such table».

Файл теста (Pest или PHPUnit команда выбирает сама, как установлено на сайте):

```bash
php artisan make:test Admin/ProductsTest          # tests/Feature/Admin/ProductsTest.php
```

Pest:

```php
// tests/Feature/Admin/ProductsTest.php
use App\Models\Product;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create(), 'fastleo');
});

it('shows the products list', function (): void {
    Product::factory()->create(['name' => 'Чайник']);

    $this->get(route('fastleo.resources.index', 'products'))
        ->assertOk()
        ->assertSee('Чайник');
});

it('creates a product', function (): void {
    $this->post(route('fastleo.resources.store', 'products'), [
        'name' => 'Пылесос',
        'category' => 'Электроника',
        'is_active' => '1',
    ])->assertRedirect();

    expect(Product::where('name', 'Пылесос')->first())
        ->category->toBe('Электроника')
        ->is_active->toBeTrue();
});

it('requires a name', function (): void {
    $this->post(route('fastleo.resources.store', 'products'), ['category' => 'Электроника'])
        ->assertSessionHasErrors('name');
});

it('updates a product', function (): void {
    $product = Product::factory()->create();

    $this->put(route('fastleo.resources.update', ['products', $product->id]), [
        'name' => 'Утюг',
        'category' => $product->category,
    ])->assertRedirect();

    expect($product->refresh()->name)->toBe('Утюг');
});
```

PHPUnit — тот же код методами класса `Tests\TestCase` (пример — в [работе с Claude](claude.md#порядок-работы)).

Форма отправляет значения под именами полей из `fields()`; галочка `Toggle`/`Checkbox` — `'1'`, пустая галочка — отсутствие ключа. Вложенные записи HasMany — массив строк под именем связи, у существующих строк — их `id` (`'links' => [['id' => '5', 'title' => '…'], ['title' => 'Новая']]`; запись, которой нет в массиве, удаляется), HasOne — массив полей (`'banner' => ['title' => '…']`), многоязычные поля — массив по языкам (`'title' => ['ru' => '…', 'en' => '…']`).

## Маршруты

Адрес раздела (`products`) — первый параметр, ключ записи — второй.

| Маршрут | Метод | Что |
|---|---|---|
| `fastleo.resources.index` | GET | список (`?q=…` — поиск; фильтры и сортировка — как в адресной строке браузера) |
| `fastleo.resources.create` | GET | форма создания |
| `fastleo.resources.store` | POST | создать |
| `fastleo.resources.show` | GET | просмотр (если не выключен `record_view`: тогда 404) |
| `fastleo.resources.edit` | GET | форма изменения |
| `fastleo.resources.update` | PUT | сохранить |
| `fastleo.resources.destroy` | DELETE | удалить (в корзину при `SoftDeletes`) |
| `fastleo.resources.restore` | POST | восстановить из корзины |
| `fastleo.resources.force-delete` | DELETE | удалить навсегда |
| `fastleo.resources.action` | POST | действие: `route(…, ['products', 'publish-products'])`, в теле `ids` и поля мини-формы под ключом `data` |
| `fastleo.resources.toggle` | POST | быстрый переключатель поля с `quickToggle()`: `route(…, ['products', $id, 'is_active'])`, в теле `value` = `1` или `0` (JSON → `{"value": true}`) |
| `fastleo.resources.export` | GET | выгрузка csv |
| `fastleo.resources.related.store` | POST | быстрое добавление из [связанной таблицы](related.md#быстрое-добавление) (`quickAdd`): `route(…, ['products', $id, 'reviews'])`, поля в теле под ключом `data`, ответ JSON (201 или 422); отправляйте `postJson` |

Полный список — `php artisan route:list --name=fastleo`.

## Права

Если у модели есть Policy, проверьте, что запрет работает — это защищает от ошибки в Policy:

```php
it('forbids editors to delete products', function (): void {
    $editor = User::factory()->admin()->create(['role' => 'editor']);
    $product = Product::factory()->create();

    $this->actingAs($editor, 'fastleo')
        ->delete(route('fastleo.resources.destroy', ['products', $product->id]))
        ->assertForbidden();

    $this->assertModelExists($product);
});

it('does not let a site user into the admin panel', function (): void {
    $this->actingAs(User::factory()->create(), 'fastleo')
        ->get(route('fastleo.resources.index', 'products'))
        ->assertRedirect(route('fastleo.login'));
});
```

## Действия

```php
it('publishes selected products', function (): void {
    $products = Product::factory()->count(2)->create(['is_active' => false]);

    $this->post(route('fastleo.resources.action', ['products', 'publish-products']), [
        'ids' => $products->pluck('id')->all(),
    ])->assertRedirect();

    expect(Product::where('is_active', true)->count())->toBe(2);
});
```

Ключ действия — часть адреса из имени класса (`PublishProducts` → `publish-products`) или свойство `$key` ([действия](actions.md)).

Если у действия есть мини-форма, её поля передаются под ключом `data`, как в форме админки:

```php
$this->post(route('fastleo.resources.action', ['products', 'schedule-products']), [
    'ids' => $products->pluck('id')->all(),
    'data' => ['published_on' => '2026-11-01'],
])->assertRedirect();
```

Тело запросов `related.store` и мини-формы действия — всегда `data[поле]`. Поля, отправленные плоско, не попадут в проверку. Ошибка проверки отвечает по-разному: `related.store` (быстрое добавление) — 422 в JSON с `errors`; `assertSessionHasNoErrors()` такой ответ не заметит, поэтому проверяйте код ответа и запись в базе. Мини-форма действия отвечает редиректом с ошибками в сессии: `assertSessionHasErrors()` их видит.

## Уведомления и настройки сайта

- Уведомления колокольчика (`Fastleo::notifyAdmins()`, `Fastleo::notify()`) лежат в таблице `fastleo_notifications`, если колокольчик включён — в тесте `config(['fastleo.notifications.bell' => true])`; содержимое — в JSON-колонке `data`: `DB::table('fastleo_notifications')->count()` или `\Fastleo\Notifications\AdminNotification::query()->latest('id')->first()->data['title']`.
- Настройки сайта в сидере и тесте записывает `app(\Fastleo\Support\SettingsStore::class)->save(new SiteSettings, ['phone' => '+7 900 000-00-00'])`.

## Проверка описания разделов

`fastleo:doctor` находит ошибки описания (поле без колонки, поле вне `$fillable`, нет каста у JSON-поля) раньше, чем их увидит администратор. Запускайте его тестом — тогда он выполняется в CI вместе с остальными:

```php
it('has no fastleo configuration errors', function (): void {
    $this->artisan('fastleo:doctor')->assertSuccessful();
});
```

Команда завершается ошибкой только при ошибках; предупреждения (очередь `sync`, нет `public/storage`) тест не роняют. Список проверок — в [работе с Claude](claude.md#fastleodoctor).

## Файлы

Поля `Image`, `File` и `Gallery` хранят путь файла на диске `fastleo.files.disk` (`uploads/products/kettle.jpg`). Форма отправляет путь файла из [файлового менеджера](files.md), а наличие файла на диске не проверяет — в тесте достаточно передать путь внутри `fastleo.files.root`:

```php
$this->post(route('fastleo.resources.store', 'products'), [
    'name' => 'Чайник',
    'category' => 'Электроника',
    'photo' => 'uploads/products/kettle.jpg',
])->assertRedirect();
```

Путь вне корня загрузок (`../.env`) или с чужим расширением вернёт ошибку проверки поля.
