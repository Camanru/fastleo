<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\NoCreateArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\RestrictedCategoryPolicy;
use Fastleo\Tests\Fixtures\QuickAdd\ArticleResource;
use Fastleo\Tests\Fixtures\QuickAdd\CategoryResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('QuickAdd');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
});

afterEach(function (): void {
    CategoryResource::$fields = ['title', 'views'];
    ArticleResource::$foreignMode = 'normal';
    ArticleResource::$hideViewsFrom = null;
});

/**
 * Адрес быстрого добавления статьи в рубрику.
 */
function quickAddUrl(Category $category, string $relation = 'articles'): string
{
    return route('fastleo.resources.related.store', ['categories', $category->id, $relation]);
}

describe('the form', function (): void {
    it('is drawn under the table with the fields from quickAdd and the label of the button', function (): void {
        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertSee('data-quick-add="articles"', false)
            ->assertSee(quickAddUrl($this->category), false)
            ->assertSee('Заголовок')
            ->assertSee('Просмотры')
            ->assertDontSee('Пометка')
            ->assertDontSee('Важная')
            ->assertSee('Добавить статью');
    });

    it('is not drawn without quickAdd, but the label of the button stays', function (): void {
        CategoryResource::$fields = null;

        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertDontSee('data-quick-add', false)
            ->assertSee('Добавить статью');
    });

    it('gives the fields ids of their own parent, so that tables of several parents on a page do not share them', function (): void {
        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertSee('quick-articles-'.$this->category->id.'-title', false);
    });

    it('is not drawn when every field of it is hidden from this admin', function (): void {
        CategoryResource::$fields = ['views'];
        ArticleResource::$hideViewsFrom = 'manager@example.com';
        $this->actingAs(admin(['email' => 'manager@example.com']), Fastleo::GUARD);

        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertDontSee('data-quick-add', false)
            ->assertSee('Добавить статью');
    });

    it('is not drawn for an admin who may not create the child record', function (): void {
        Gate::policy(Article::class, NoCreateArticlePolicy::class);

        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertDontSee('data-quick-add', false);
    });
});

describe('the record', function (): void {
    it('is created under this parent and the unlisted fields take their defaults', function (): void {
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка', 'views' => 5]])
            ->assertCreated()
            ->assertExactJson(['ok' => true]);

        $this->assertDatabaseHas('articles', ['title' => 'Решётка', 'views' => 5, 'category_id' => $this->category->id, 'status' => 'published']);
        expect(Article::query()->count())->toBe(1);
    });

    it('writes the empty value of a field that was left blank in the form', function (): void {
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка', 'views' => '']])->assertCreated();

        $this->assertDatabaseHas('articles', ['title' => 'Решётка', 'views' => 0]);
    });

    it('fills the fields that are not in the form the way the regular form does: defaults that differ from the column, the hidden field and the slug', function (): void {
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка вентиляционная']])->assertCreated();

        $this->assertDatabaseHas('articles', [
            'title' => 'Решётка вентиляционная',
            'status' => 'published',
            'type' => 'event',
            'slug' => 'reshyotka-ventilyatsionnaya',
        ]);
    });

    it('sets the parent through the relation when the form of the child has no field for it or keeps it read-only', function (string $mode): void {
        ArticleResource::$foreignMode = $mode;

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])->assertCreated();

        $this->assertDatabaseHas('articles', ['title' => 'Решётка', 'category_id' => $this->category->id]);
    })->with(['read-only' => 'readonly', 'absent' => 'absent']);

    it('does not let the input override the parent when the key is read-only', function (): void {
        ArticleResource::$foreignMode = 'readonly';
        $other = Category::factory()->create();

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка', 'category_id' => $other->id]])->assertCreated();

        $this->assertDatabaseHas('articles', ['title' => 'Решётка', 'category_id' => $this->category->id]);
    });

    it('lands in the activity log through the writer', function (): void {
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])->assertCreated();

        $activity = Activity::query()->where('event', 'created')->latest('id')->first();

        expect($activity?->subject_id)->toBe((string) Article::query()->value('id'));
    });

    it('does not take a parent or a field from the input that was not asked for', function (): void {
        $other = Category::factory()->create();

        $this->postJson(quickAddUrl($this->category), ['data' => [
            'title' => 'Решётка',
            'category_id' => $other->id,
            'status' => 'published',
            'is_featured' => true,
            'excerpt' => 'Пометка',
        ]])->assertCreated();

        $this->assertDatabaseHas('articles', ['title' => 'Решётка', 'category_id' => $this->category->id, 'status' => 'published', 'type' => 'event', 'is_featured' => false, 'excerpt' => null]);
    });
});

describe('a refusal while saving', function (): void {
    it('explains why the database refused the record and creates nothing', function (): void {
        Article::creating(function (): never {
            throw new QueryException('sqlite', 'insert into articles', [], new PDOException('FOREIGN KEY constraint failed'));
        });

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Не удалось сохранить или удалить: запись связана с другими записями или указана несуществующая связанная запись.', 'errors' => []]);

        expect(Article::query()->count())->toBe(0);
    });

    it('says so when an observer of the site cancels the record', function (): void {
        Article::creating(fn (): bool => false);

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Сайт отменил сохранение (обработчик модели вернул false). Изменения не записаны.', 'errors' => []]);

        expect(Article::query()->count())->toBe(0);
    });
});

describe('the check', function (): void {
    it('answers 422 with the errors by field and creates nothing', function (): void {
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => '', 'views' => -3]])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['title', 'views']]);

        expect(Article::query()->count())->toBe(0);
    });

    it('answers 422 when the whole body is missing', function (): void {
        $this->postJson(quickAddUrl($this->category))->assertUnprocessable();

        $this->postJson(quickAddUrl($this->category), ['data' => 'text'])->assertUnprocessable();
    });
});

describe('the rights', function (): void {
    it('need update of the parent', function (): void {
        Gate::policy(Category::class, RestrictedCategoryPolicy::class);
        $closed = Category::factory()->create(['title' => 'Закрыто']);

        $this->postJson(quickAddUrl($closed), ['data' => ['title' => 'Решётка']])->assertForbidden();
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])->assertCreated();

        expect(Article::query()->count())->toBe(1);
    });

    it('need create of the child section', function (): void {
        Gate::policy(Article::class, NoCreateArticlePolicy::class);

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])->assertForbidden();

        expect(Article::query()->count())->toBe(0);
    });

    it('need a signed-in admin', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])->assertUnauthorized();
    });
});

describe('a field that is hidden from this admin', function (): void {
    it('does not break the page of the parent and is left out of the form of that admin', function (): void {
        ArticleResource::$hideViewsFrom = 'manager@example.com';
        $this->actingAs(admin(['email' => 'manager@example.com']), Fastleo::GUARD);

        $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))->assertOk();
        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
            ->assertOk()
            ->assertSee('data-quick-add="articles"', false)
            ->assertDontSee('Просмотры');
        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка', 'views' => 9]])->assertCreated();

        $this->assertDatabaseHas('articles', ['title' => 'Решётка', 'views' => 0]);
    });

    it('stays in the form of the other admins', function (): void {
        ArticleResource::$hideViewsFrom = 'manager@example.com';

        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))->assertOk()->assertSee('Просмотры');
    });
});

describe('the address', function (): void {
    it('is not found for a relation without quickAdd, an unknown relation, parent or section', function (): void {
        CategoryResource::$fields = null;

        $this->postJson(quickAddUrl($this->category), ['data' => ['title' => 'Решётка']])->assertNotFound();

        CategoryResource::$fields = ['title'];

        $this->postJson(quickAddUrl($this->category, 'nothing'), ['data' => ['title' => 'Решётка']])->assertNotFound();
        $this->postJson(route('fastleo.resources.related.store', ['categories', 999999, 'articles']), ['data' => ['title' => 'Решётка']])->assertNotFound();
        $this->postJson(route('fastleo.resources.related.store', ['nothing', $this->category->id, 'articles']), ['data' => ['title' => 'Решётка']])->assertNotFound();
        $this->postJson(route('fastleo.resources.related.store', ['categories', 'abc', 'articles']), ['data' => ['title' => 'Решётка']])->assertNotFound();

        expect(Article::query()->count())->toBe(0);
    });
});

describe('the description', function (): void {
    it('rejects fields that cannot sit in a small form', function (string $field): void {
        CategoryResource::$fields = [$field];

        Fastleo::resources()->find('categories')->listRelations();
    })->with([
        'the foreign key' => ['category_id'],
        'a field of another section' => ['nothing'],
        'a field shown by a condition' => ['excerpt'],
        'a translatable field' => ['source_url'],
    ])->throws(LogicException::class, 'быстрое добавление');
});
