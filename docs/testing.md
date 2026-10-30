# Тесты разделов в сайте

Раздел админки — обычные маршруты Laravel, поэтому его тестируют обычными Feature-тестами сайта: войти администратором, открыть страницу, отправить форму, проверить базу. Своих хелперов у пакета нет — хватает стандартных `actingAs()`, `get()`, `post()`.

## Администратор в тесте

Администратор — пользователь, у которого `canAccessFastleo()` возвращает `true` (по умолчанию — колонка `fastleo_admin`). Колонки `fastleo_admin` нет в `$fillable`, поэтому ставьте её через `forceFill()` или состояние фабрики:

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

## Пример

Pest:

```php
// tests/Feature/Admin/DoctorsTest.php
use App\Models\Doctor;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create(), 'fastleo');
});

it('shows the doctors list', function (): void {
    Doctor::factory()->create(['name' => 'Иванов']);

    $this->get(route('fastleo.resources.index', 'doctors'))
        ->assertOk()
        ->assertSee('Иванов');
});

it('creates a doctor', function (): void {
    $this->post(route('fastleo.resources.store', 'doctors'), [
        'name' => 'Петров',
        'specialty' => 'Хирург',
        'accepts_appointments' => '1',
    ])->assertRedirect();

    expect(Doctor::where('name', 'Петров')->first())
        ->specialty->toBe('Хирург')
        ->accepts_appointments->toBeTrue();
});

it('requires a name', function (): void {
    $this->post(route('fastleo.resources.store', 'doctors'), ['specialty' => 'Хирург'])
        ->assertSessionHasErrors('name');
});

it('updates a doctor', function (): void {
    $doctor = Doctor::factory()->create();

    $this->put(route('fastleo.resources.update', ['doctors', $doctor->id]), [
        'name' => 'Сидоров',
        'specialty' => $doctor->specialty,
    ])->assertRedirect();

    expect($doctor->refresh()->name)->toBe('Сидоров');
});
```

PHPUnit — тот же код методами класса `Tests\TestCase` (пример — в [работе с Claude](claude.md#порядок-работы)).

Форма отправляет значения под именами полей из `fields()`; галочка `Toggle`/`Checkbox` — `'1'`, пустая галочка — отсутствие ключа. Вложенные записи HasMany — массив строк под именем связи, у существующих строк — их `id` (`'links' => [['id' => '5', 'title' => '…'], ['title' => 'Новая']]`; запись, которой нет в массиве, удаляется), HasOne — массив полей (`'banner' => ['title' => '…']`), многоязычные поля — массив по языкам (`'title' => ['ru' => '…', 'en' => '…']`).

## Маршруты

Адрес раздела (`doctors`) — первый параметр, ключ записи — второй.

| Маршрут | Метод | Что |
|---|---|---|
| `fastleo.resources.index` | GET | список (`?q=…` — поиск; фильтры и сортировка — как в адресной строке браузера) |
| `fastleo.resources.create` | GET | форма создания |
| `fastleo.resources.store` | POST | создать |
| `fastleo.resources.show` | GET | просмотр |
| `fastleo.resources.edit` | GET | форма изменения |
| `fastleo.resources.update` | PUT | сохранить |
| `fastleo.resources.destroy` | DELETE | удалить (в корзину при `SoftDeletes`) |
| `fastleo.resources.restore` | POST | восстановить из корзины |
| `fastleo.resources.force-delete` | DELETE | удалить навсегда |
| `fastleo.resources.action` | POST | действие: `route(…, ['doctors', 'publish-doctors'])`, в теле `ids` и поля мини-формы под ключом `data` |
| `fastleo.resources.toggle` | POST | быстрый переключатель поля с `quickToggle()`: `route(…, ['doctors', $id, 'is_active'])`, в теле `value` = `1` или `0` (JSON → `{"value": true}`) |
| `fastleo.resources.export` | GET | выгрузка xlsx/csv |

Полный список — `php artisan route:list --name=fastleo`.

## Права

Если у модели есть Policy, проверьте, что запрет работает — это защищает от ошибки в Policy:

```php
it('forbids editors to delete doctors', function (): void {
    $editor = User::factory()->admin()->create(['role' => 'editor']);
    $doctor = Doctor::factory()->create();

    $this->actingAs($editor, 'fastleo')
        ->delete(route('fastleo.resources.destroy', ['doctors', $doctor->id]))
        ->assertForbidden();

    $this->assertModelExists($doctor);
});

it('does not let a site user into the admin panel', function (): void {
    $this->actingAs(User::factory()->create(), 'fastleo')
        ->get(route('fastleo.resources.index', 'doctors'))
        ->assertRedirect(route('fastleo.login'));
});
```

## Действия

```php
it('publishes selected doctors', function (): void {
    $doctors = Doctor::factory()->count(2)->create(['published' => false]);

    $this->post(route('fastleo.resources.action', ['doctors', 'publish-doctors']), [
        'ids' => $doctors->pluck('id')->all(),
    ])->assertRedirect();

    expect(Doctor::where('published', true)->count())->toBe(2);
});
```

Ключ действия — часть адреса из имени класса (`PublishDoctors` → `publish-doctors`) или свойство `$key` ([действия](actions.md)).

Если у действия есть мини-форма, её поля передаются под ключом `data`, как в форме админки:

```php
$this->post(route('fastleo.resources.action', ['doctors', 'schedule-doctors']), [
    'ids' => $doctors->pluck('id')->all(),
    'data' => ['published_on' => '2026-11-01'],
])->assertRedirect();
```

## Проверка описания разделов

`fastleo:doctor` находит ошибки описания (поле без колонки, поле вне `$fillable`, нет каста у JSON-поля) раньше, чем их увидит администратор. Запускайте его тестом — тогда он выполняется в CI вместе с остальными:

```php
it('has no fastleo configuration errors', function (): void {
    $this->artisan('fastleo:doctor')->assertSuccessful();
});
```

Команда завершается ошибкой только при ошибках; предупреждения (очередь `sync`, нет `public/storage`) тест не роняют. Список проверок — в [работе с Claude](claude.md#fastleodoctor).

## Файлы

Поля `Image`, `File` и `Gallery` хранят путь файла на диске `fastleo.files.disk` (`uploads/doctors/ivanov.jpg`). Форма отправляет путь файла из [файлового менеджера](files.md), а наличие файла на диске не проверяет — в тесте достаточно передать путь внутри `fastleo.files.root`:

```php
$this->post(route('fastleo.resources.store', 'doctors'), [
    'name' => 'Иванов',
    'specialty' => 'Терапевт',
    'photo' => 'uploads/doctors/ivanov.jpg',
])->assertRedirect();
```

Путь вне корня загрузок (`../.env`) или с чужим расширением вернёт ошибку проверки поля.
