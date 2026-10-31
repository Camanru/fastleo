<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Resources\GuardedCategoryResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('index', function (): void {
    it('lists records with their fields', function (): void {
        Category::factory()->create(['title' => 'Новости', 'code' => 'news']);

        $response = $this->get(route('fastleo.resources.index', 'categories'));

        $response->assertOk()
            ->assertSee('<title>Категории', false)
            ->assertSee('Новости')
            ->assertSee('news');
    });

    it('shows the description and the record count under the list title', function (): void {
        Category::factory()->count(2)->create();

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('Новости и материалы блога.')
            ->assertSee('data-count', false);
    });

    it('shows the resource in the menu under its group', function (): void {
        $response = $this->get(route('fastleo.dashboard'));

        $response->assertOk()
            ->assertSee('Контент')
            ->assertSee(route('fastleo.resources.index', 'categories'));
    });

    it('returns 404 for an unknown section', function (): void {
        $this->get('/fastleo/unknown')->assertNotFound();
    });

    it('redirects a guest to the login form', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->get(route('fastleo.resources.index', 'categories'))->assertRedirect(route('fastleo.login'));
    });

    it('escapes record values', function (): void {
        Category::factory()->create(['title' => '<script>alert(1)</script>']);

        $response = $this->get(route('fastleo.resources.index', 'categories'));

        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    });
});

it('returns to the form of a record whose key is zero when the check fails', function (): void {
    DB::table('categories')->insert(['id' => 0, 'title' => 'Нулевая']);

    $this->put(route('fastleo.resources.update', ['categories', '0']), ['title' => ''])
        ->assertRedirect(route('fastleo.resources.edit', ['categories', '0']))
        ->assertSessionHasErrors('title');
})->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'MySQL подставляет следующий номер вместо нулевого ключа');

describe('create and store', function (): void {
    it('renders an empty form', function (): void {
        $this->get(route('fastleo.resources.create', 'categories'))
            ->assertOk()
            ->assertSee('Создать: Категория')
            ->assertSee('name="title"', false)
            ->assertSee('Сохранить и создать ещё');
    });

    it('creates a record and opens it for editing', function (): void {
        $response = $this->post(route('fastleo.resources.store', 'categories'), ['title' => 'Акции', 'code' => 'sales']);

        $category = Category::query()->sole();
        expect($category->only('title', 'code'))->toBe(['title' => 'Акции', 'code' => 'sales']);
        $response->assertRedirect(route('fastleo.resources.edit', ['categories', $category->id]))
            ->assertSessionHas('fastleo.success', 'Сохранено.');
    });

    it('goes where the pressed button says after saving', function (string $after, string $route): void {
        $response = $this->post(route('fastleo.resources.store', 'categories'), ['title' => 'Акции', '_after' => $after]);

        $response->assertRedirect(route($route, 'categories'));
    })->with([
        'save and exit' => ['index', 'fastleo.resources.index'],
        'save and create another' => ['create', 'fastleo.resources.create'],
    ]);

    it('ignores an unknown redirect target', function (): void {
        $response = $this->post(route('fastleo.resources.store', 'categories'), ['title' => 'Акции', '_after' => 'https://evil.example']);

        $response->assertRedirect(route('fastleo.resources.edit', ['categories', Category::query()->sole()->id]));
    });

    it('rejects an empty required field with a Russian message', function (): void {
        app()->setLocale('en');

        $response = $this->from(route('fastleo.resources.create', 'categories'))
            ->post(route('fastleo.resources.store', 'categories'), ['title' => '']);

        $response->assertRedirect(route('fastleo.resources.create', 'categories'))
            ->assertSessionHasErrors(['title' => 'Заполните поле «Название».']);
        expect(Category::query()->count())->toBe(0);
    });

    it('returns to the form after an error even without a referrer', function (): void {
        $category = Category::factory()->create();

        $this->post(route('fastleo.resources.store', 'categories'), ['title' => ''])
            ->assertRedirect(route('fastleo.resources.create', 'categories'));
        $this->put(route('fastleo.resources.update', ['categories', $category->id]), ['title' => ''])
            ->assertRedirect(route('fastleo.resources.edit', ['categories', $category->id]));
    });

    it('rejects a value longer than the field allows', function (): void {
        $response = $this->post(route('fastleo.resources.store', 'categories'), ['title' => 'Акции', 'code' => str_repeat('a', 51)]);

        $response->assertSessionHasErrors(['code' => 'Длина поля «Код» должна быть не больше 50 символов.']);
    });

    it('keeps the site wording of a validation message', function (): void {
        resolve('translator')->addLines(['validation.required' => 'Своё: :attribute'], 'ru');

        $response = $this->post(route('fastleo.resources.store', 'categories'), ['title' => '']);

        $response->assertSessionHasErrors(['title' => 'Своё: Название']);
    });

    it('turns blank optional values into null', function (): void {
        $this->post(route('fastleo.resources.store', 'categories'), ['title' => '  Акции  ', 'code' => '   ']);

        expect(Category::query()->sole()->only('title', 'code'))->toBe(['title' => 'Акции', 'code' => null]);
    });
});

describe('edit and update', function (): void {
    it('renders the form with the current values', function (): void {
        $category = Category::factory()->create(['title' => 'Новости']);

        $this->get(route('fastleo.resources.edit', ['categories', $category->id]))
            ->assertOk()
            ->assertSee('value="Новости"', false)
            ->assertDontSee('Сохранить и создать ещё');
    });

    it('updates the record', function (): void {
        $category = Category::factory()->create(['title' => 'Новости']);

        $response = $this->put(route('fastleo.resources.update', ['categories', $category->id]), ['title' => 'Статьи', 'code' => 'articles']);

        $response->assertRedirect(route('fastleo.resources.edit', ['categories', $category->id]));
        expect($category->refresh()->only('title', 'code'))->toBe(['title' => 'Статьи', 'code' => 'articles']);
    });

    it('returns 404 for a missing record or a malformed id', function (string $id): void {
        $this->get('/fastleo/categories/'.$id.'/edit')->assertNotFound();
    })->with(['missing' => ['999'], 'letters' => ['abc'], 'too long' => ['1234567890123456789']]);

    it('shows a friendly message when the database rejects the values', function (): void {
        Category::factory()->create(['code' => 'news']);
        $category = Category::factory()->create(['title' => 'Старое', 'code' => 'old']);

        $response = $this->from(route('fastleo.resources.edit', ['categories', $category->id]))
            ->put(route('fastleo.resources.update', ['categories', $category->id]), ['title' => 'Новое', 'code' => 'news']);

        $response->assertRedirect(route('fastleo.resources.edit', ['categories', $category->id]))
            ->assertSessionHas('fastleo.error', 'Не удалось сохранить: такое значение уже есть у другой записи (адрес, код, email).')
            ->assertSessionHasInput('title', 'Новое');
        expect($category->refresh()->title)->toBe('Старое');
    });
});

describe('show', function (): void {
    it('renders the record values', function (): void {
        $category = Category::factory()->create(['title' => 'Новости', 'code' => 'news']);

        $this->get(route('fastleo.resources.show', ['categories', $category->id]))
            ->assertOk()
            ->assertSee('Просмотр: Категория')
            ->assertSee('Новости')
            ->assertSee('news');
    });
});

describe('destroy', function (): void {
    it('deletes the record for good when the model has no trash', function (): void {
        $category = Category::factory()->create();

        $response = $this->delete(route('fastleo.resources.destroy', ['categories', $category->id]));

        $response->assertRedirect(route('fastleo.resources.index', 'categories'))
            ->assertSessionHas('fastleo.success', 'Запись удалена.');
        $this->assertModelMissing($category);
    });

    it('explains why the database refused to delete a record', function (): void {
        $category = Category::factory()->create();
        Category::deleting(function (): never {
            throw new QueryException('sqlite', 'delete from categories', [], new PDOException('FOREIGN KEY constraint failed'));
        });

        $response = $this->from(route('fastleo.resources.edit', ['categories', $category->id]))
            ->delete(route('fastleo.resources.destroy', ['categories', $category->id]));

        $response->assertRedirect(route('fastleo.resources.edit', ['categories', $category->id]))
            ->assertSessionHas('fastleo.error', 'Не удалось сохранить или удалить: запись связана с другими записями или указана несуществующая связанная запись.');
        $this->assertModelExists($category);
    });

    it('returns 404 for trash actions when the model has no trash', function (): void {
        $category = Category::factory()->create();

        $this->post(route('fastleo.resources.restore', ['categories', $category->id]))->assertNotFound();
        $this->delete(route('fastleo.resources.force-delete', ['categories', $category->id]))->assertNotFound();
        $this->assertModelExists($category);
    });
});

describe('fillable', function (): void {
    it('does not write request keys that are not fields of the resource', function (): void {
        $this->post(route('fastleo.resources.store', 'categories'), ['title' => 'Акции', 'internal_code' => 'hack', 'id' => 500]);

        $category = Category::query()->sole();
        expect($category->internal_code)->toBeNull()
            ->and($category->id)->not->toBe(500);
    });

    it('fails loudly when a field of the resource is not fillable in the model', function (): void {
        useFixtureResources('Resources');
        $this->withoutExceptionHandling();

        $this->post(route('fastleo.resources.store', 'guarded-categories'), ['title' => 'Акции', 'internal_code' => 'x']);
    })->throws(LogicException::class, 'Поле «internal_code» ресурса '.GuardedCategoryResource::class.' не разрешено моделью '.Category::class);
});
