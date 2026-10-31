<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Related;
use Fastleo\Support\ReturnUrl;
use Fastleo\Tests\Fixtures\Policies\HiddenArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\NoUpdateArticlePolicy;
use Fastleo\Tests\Fixtures\Policies\NoViewCategoryPolicy;
use Fastleo\Tests\Fixtures\Policies\RestrictedCategoryPolicy;
use Fastleo\Tests\Fixtures\Related\ArticleResource as ChildArticleResource;
use Fastleo\Tests\Fixtures\RelatedBroken\CategoryResource as BrokenCategoryResource;
use Fastleo\Tests\Fixtures\RelatedColumns\CategoryResource as ColumnsCategoryResource;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Related');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
});

afterEach(function (): void {
    BrokenCategoryResource::$mode = 'conflict';
    ColumnsCategoryResource::$columns = ['status', 'title'];
});

/**
 * Адрес таблицы связанных статей рубрики.
 *
 * @param  array<string, mixed>  $query
 */
function relatedUrl(Category $category, array $query = []): string
{
    return route('fastleo.resources.related', ['categories', $category->id, 'articles', ...$query]);
}

/**
 * Статья рубрики.
 *
 * @param  array<string, mixed>  $attributes
 */
function categoryArticle(Category $category, array $attributes = []): Article
{
    return Article::factory()->create(['category_id' => $category->id, 'status' => 'draft', ...$attributes]);
}

describe('description', function (): void {
    it('binds the table to the relation and the child section', function (): void {
        $related = Fastleo::resources()->find('categories')->listRelations()['articles'];

        expect($related)->toBeInstanceOf(Related::class)
            ->and($related->getLabel())->toBe('Статьи рубрики')
            ->and($related->getPerPage())->toBe(2)
            ->and($related->foreignKey())->toBe('category_id')
            ->and($related->child()::slug())->toBe('articles');
    });

    it('takes the label of the child section by default', function (): void {
        expect(Related::make('articles', ChildArticleResource::class)->bind(Fastleo::resources()->find('categories'))->getLabel())
            ->toBe(Fastleo::resources()->find('articles')::label());
    });

    it('rejects a table that is described wrongly', function (string $mode, string $message): void {
        useFixtureResources('RelatedBroken');
        BrokenCategoryResource::$mode = $mode;

        Fastleo::resources()->find('categories')->listRelations();
    })->with([
        'a relation that is not HasMany' => ['not-has-many', 'HasMany'],
        'a missing relation method' => ['no-method', 'missing'],
        'a class that is not a section' => ['no-resource', 'Text'],
        'a section of another model' => ['wrong-model', 'children'],
        'the same relation twice' => ['duplicate', 'описана дважды'],
        'the relation shown also as a form field' => ['conflict', 'перезапишет'],
    ])->throws(LogicException::class);

    it('lets the same relation stay in the creation form only', function (): void {
        useFixtureResources('RelatedBroken');
        BrokenCategoryResource::$mode = 'create-only';

        expect(Fastleo::resources()->find('categories')->listRelations())->toHaveKey('articles');
    });
});

it('shows nothing when the parent has no key to look the records up by, instead of the records without a parent', function (): void {
    Article::factory()->create(['title' => 'Без рубрики', 'category_id' => null]);
    $related = Fastleo::resources()->find('categories')->listRelations()['articles'];

    $scoped = Article::query()->tap($related->scopeFor(new Category));

    expect($scoped->count())->toBe(0);
});

describe('the record page', function (): void {
    it('shows a placeholder that loads the table on the view page and on the edit page', function (): void {
        $this->get(route('fastleo.resources.show', ['categories', $this->category->id]))
            ->assertOk()
            ->assertSee('data-related="articles"', false)
            ->assertSee('\\/categories\\/'.$this->category->id.'\\/related\\/articles?return=', false);

        $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))
            ->assertOk()
            ->assertSee('data-related="articles"', false);
    });

    it('has no table on the creation form', function (): void {
        $this->get(route('fastleo.resources.create', 'categories'))->assertOk()->assertDontSee('data-related', false);
    });

    it('hides a table whose section the admin may not list', function (): void {
        Gate::policy(Article::class, HiddenArticlePolicy::class);

        $this->get(route('fastleo.resources.show', ['categories', $this->category->id]))
            ->assertOk()
            ->assertDontSee('data-related', false);
    });
});

describe('the table', function (): void {
    it('lists only the records of this parent', function (): void {
        $other = Category::factory()->create();
        categoryArticle($this->category, ['title' => 'Статья про воздух']);
        categoryArticle($other, ['title' => 'Чужая статья']);

        $this->get(relatedUrl($this->category))
            ->assertOk()
            ->assertSee('Статьи рубрики')
            ->assertSee('Статья про воздух')
            ->assertDontSee('Чужая статья');
    });

    it('draws the badge of the status and the empty text', function (): void {
        $this->get(relatedUrl($this->category))->assertOk()->assertSee('Записей пока нет.');

        categoryArticle($this->category, ['status' => 'published']);

        $this->get(relatedUrl($this->category))->assertOk()->assertSee('bg-success-soft text-success', false)->assertSee('Опубликовано');
    });

    it('searches, sorts and pages by its own address', function (): void {
        foreach (['Альфа', 'Бета', 'Гамма'] as $title) {
            categoryArticle($this->category, ['title' => $title]);
        }

        $this->get(relatedUrl($this->category, ['q' => 'Бет']))->assertOk()->assertSee('Бета')->assertDontSee('Альфа');
        $this->get(relatedUrl($this->category, ['sort' => 'title', 'direction' => 'desc']))->assertOk()->assertSeeInOrder(['Гамма', 'Бета'])->assertDontSee('Альфа');
        $this->get(relatedUrl($this->category, ['sort' => 'title', 'direction' => 'asc', 'page' => 2]))->assertOk()->assertSee('Гамма')->assertDontSee('Альфа');
    });

    it('offers to add a record with the parent and the way back filled in', function (): void {
        $page = route('fastleo.resources.edit', ['categories', $this->category->id]);
        $url = route('fastleo.resources.create', ['resource' => 'articles', 'prefill' => ['category_id' => (string) $this->category->id], 'return' => $page]);

        $this->get(relatedUrl($this->category, ['return' => $page]))
            ->assertOk()
            ->assertSee(e($url), false);
    });

    it('draws row links and buttons that lead back to the page', function (): void {
        $article = categoryArticle($this->category);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->get(relatedUrl($this->category, ['return' => $page]))
            ->assertOk()
            ->assertSee(e(route('fastleo.resources.edit', ['articles', $article->id, 'return' => $page])), false)
            ->assertSee('name="_return" value="'.$page.'"', false)
            ->assertSee('Сделать важной');
    });

    it('does not offer to add or edit without the rights', function (): void {
        $article = categoryArticle($this->category);
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);

        $this->get(relatedUrl($this->category))
            ->assertOk()
            ->assertDontSee('Добавить')
            ->assertDontSee(route('fastleo.resources.edit', ['articles', $article->id]), false)
            ->assertSee(route('fastleo.resources.show', ['articles', $article->id]), false);
    });

    it('is closed to the admin who may not open the parent record', function (): void {
        Gate::policy(Category::class, NoViewCategoryPolicy::class);

        $this->get(relatedUrl($this->category))->assertForbidden();
    });

    it('is closed to the admin who may not list the child section', function (): void {
        Gate::policy(Article::class, HiddenArticlePolicy::class);

        $this->get(relatedUrl($this->category))->assertForbidden();
    });

    it('does not offer to add while the parent may not be changed, though the child may be created', function (): void {
        Gate::policy(Category::class, RestrictedCategoryPolicy::class);
        $closed = Category::factory()->create(['title' => 'Закрыто']);

        $this->get(relatedUrl($closed))->assertOk()->assertDontSee('Добавить');
        $this->get(relatedUrl($this->category))->assertOk()->assertSee('Добавить');
    });

    it('answers 404 for an unknown relation or record', function (): void {
        $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'missing']))->assertNotFound();
        $this->get(route('fastleo.resources.related', ['categories', 99999, 'articles']))->assertNotFound();
        $this->get(route('fastleo.resources.related', ['articles', 1, 'articles']))->assertNotFound();
    });
});

describe('row actions', function (): void {
    it('runs the action of the child section and returns to the page of the parent', function (): void {
        $article = categoryArticle($this->category, ['is_featured' => false]);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->from($page)->post(route('fastleo.resources.action', ['articles', 'set-is-featured-on']), ['ids' => [(string) $article->id]])
            ->assertRedirect($page)
            ->assertSessionHas('fastleo.success');

        expect($article->refresh()->is_featured)->toBeTrue();
    });
});

describe('the column of the parent', function (): void {
    it('is not drawn in the table: it is the same in every row', function (): void {
        categoryArticle($this->category);

        $this->get(relatedUrl($this->category))
            ->assertOk()
            ->assertSee('Заголовок')
            ->assertDontSee('Рубрика');
    });
});

describe('a failed action form', function (): void {
    it('is shown by the table: the window opens with the error and the input', function (): void {
        $article = categoryArticle($this->category);

        $this->get(relatedUrl($this->category))->assertSee('name="_related" value="articles"', false)->assertSee('name="_related_parent" value="'.$this->category->id.'"', false);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->from($page)->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), [
            'ids' => [(string) $article->id],
            'data' => ['code' => 'ошибка-ввода'],
            '_related' => 'articles', '_related_parent' => (string) $this->category->id,
        ])->assertRedirect($page)->assertSessionMissing('fastleo.action');

        $this->get(relatedUrl($this->category))
            ->assertOk()
            ->assertSee("open: 'stamp-articles'", false)
            ->assertSee("openIds: JSON.parse('[\\u0022".$article->id."\\u0022]')", false)
            ->assertSee('ошибка-ввода')
            ->assertSee('Код');

        // Один раз: вторая загрузка таблицы (в том числе автообновление) окно не открывает и ввод не показывает.
        $this->get(relatedUrl($this->category))->assertOk()->assertDontSee("open: 'stamp-articles'", false)->assertDontSee('ошибка-ввода');
    });

    it('belongs to the table of its own parent, not to another table of the same relation', function (): void {
        $other = Category::factory()->create();
        $article = categoryArticle($this->category);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->from($page)->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), ['ids' => [(string) $article->id], 'data' => ['code' => 'ошибка-ввода'], '_related' => 'articles', '_related_parent' => (string) $this->category->id]);

        $this->get(relatedUrl($other))->assertOk()->assertDontSee("open: 'stamp-articles'", false)->assertDontSee('ошибка-ввода');
        $this->get(relatedUrl($this->category))->assertOk()->assertSee("open: 'stamp-articles'", false)->assertSee('ошибка-ввода');
    });

    it('does not leak the input of the form into the next page', function (): void {
        $article = categoryArticle($this->category);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->from($page)->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), ['ids' => [(string) $article->id], 'data' => ['code' => 'утечка-ввода'], '_related' => 'articles', '_related_parent' => (string) $this->category->id]);
        $this->get(relatedUrl($this->category))->assertSee('утечка-ввода');

        $this->get(route('fastleo.resources.create', 'articles'))->assertOk()->assertDontSee('утечка-ввода');
    });

    it('is dropped when it is older than a minute', function (): void {
        $article = categoryArticle($this->category);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->from($page)->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), ['ids' => [(string) $article->id], 'data' => ['code' => 'ошибка-ввода'], '_related' => 'articles', '_related_parent' => (string) $this->category->id]);
        $this->travel(2)->minutes();

        $this->get(relatedUrl($this->category))->assertOk()->assertDontSee("open: 'stamp-articles'", false);
    });

    it('keeps the old way for a form outside of a related table', function (): void {
        $article = categoryArticle($this->category);

        $this->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), ['ids' => [(string) $article->id], 'data' => ['code' => 'ошибка-ввода']])
            ->assertSessionHas('fastleo.action', 'stamp-articles')
            ->assertSessionHasErrors('data.code');
    });

    it('ignores a name of a relation that is not a name', function (): void {
        $article = categoryArticle($this->category);

        $this->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), ['ids' => [(string) $article->id], 'data' => ['code' => 'ошибка-ввода'], '_related' => ['x']])
            ->assertSessionHas('fastleo.action', 'stamp-articles');
    });
});

describe('the way back', function (): void {
    it('keeps the way back in the form and drops "save and create"', function (): void {
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->get(route('fastleo.resources.create', ['resource' => 'articles', 'prefill' => ['category_id' => (string) $this->category->id], 'return' => $page]))
            ->assertOk()
            ->assertSee('name="_return" value="'.$page.'"', false)
            ->assertSee('data-back', false)
            ->assertSee('Назад')
            ->assertDontSee('value="create"', false);
    });

    it('returns to the page after "save and exit"', function (): void {
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->post(route('fastleo.resources.store', 'articles'), ['title' => 'Новая', 'status' => 'draft', 'category_id' => $this->category->id, '_return' => $page, '_after' => 'index'])
            ->assertRedirect($page);

        expect(Article::query()->where('title', 'Новая')->value('category_id'))->toBe($this->category->id);
    });

    it('stays on the form after "save" and keeps the way back', function (): void {
        $article = categoryArticle($this->category);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), ['title' => 'Правка', 'status' => 'draft', 'category_id' => $this->category->id, '_return' => $page, '_after' => 'edit'])
            ->assertRedirect(route('fastleo.resources.edit', ['articles', $article->id, 'return' => $page]));
    });

    it('returns to the page instead of "save and create" when the way back is kept', function (): void {
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->post(route('fastleo.resources.store', 'articles'), ['title' => 'Новая', 'status' => 'draft', 'category_id' => $this->category->id, '_return' => $page, '_after' => 'create'])
            ->assertRedirect($page);
    });

    it('returns to the form with the way back after a validation error', function (): void {
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->from($page)->post(route('fastleo.resources.store', 'articles'), ['title' => '', '_return' => $page])
            ->assertRedirect(route('fastleo.resources.create', ['articles', 'return' => $page]))
            ->assertSessionHasErrors('title');
    });

    it('returns to the page after deleting a record from the table', function (): void {
        $article = categoryArticle($this->category);
        $page = route('fastleo.resources.show', ['categories', $this->category->id]);

        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]), ['_return' => $page])->assertRedirect($page);

        expect(Article::query()->find($article->id))->toBeNull();
    });

    it('ignores an address that is not a page of the admin panel', function (string $return): void {
        $this->post(route('fastleo.resources.store', 'articles'), ['title' => 'Новая', 'status' => 'draft', 'category_id' => $this->category->id, '_return' => $return, '_after' => 'index'])
            ->assertRedirect(route('fastleo.resources.index', 'articles'));
    })->with([
        'another host' => ['https://evil.example/fastleo/x'],
        'a protocol-relative address' => ['//evil.example/fastleo/'],
        'a script' => ['javascript:alert(1)'],
        'a relative path' => ['/fastleo/articles'],
        'a backslash trick' => ['http://localhost/fastleo/\\evil.example'],
        'a line break' => ["http://localhost/fastleo/a\r\nLocation: x"],
    ]);

    it('accepts only a full address of the admin panel', function (): void {
        expect(ReturnUrl::sanitize(route('fastleo.resources.index', 'articles')))->toBe(route('fastleo.resources.index', 'articles'))
            ->and(ReturnUrl::sanitize(route('fastleo.dashboard')))->toBeNull()
            ->and(ReturnUrl::sanitize(request()->root().'/fastleo/articles/1'))->toBe(request()->root().'/fastleo/articles/1')
            ->and(ReturnUrl::sanitize(['a']))->toBeNull()
            ->and(ReturnUrl::sanitize(str_repeat('a', 2100)))->toBeNull();
    });
});

describe('the columns', function (): void {
    it('are the chosen fields of the child section, in the chosen order', function (): void {
        useFixtureResources('RelatedColumns');
        categoryArticle($this->category, ['title' => 'Статья про воздух', 'status' => 'published']);

        $this->get(relatedUrl($this->category))
            ->assertOk()
            ->assertSeeInOrder(['Статус', 'Заголовок', 'Статья про воздух'])
            ->assertDontSee('Рубрика')
            ->assertDontSee('Заметка');
    });

    it('are all the columns of the child list when none are chosen', function (): void {
        ColumnsCategoryResource::$columns = [];
        useFixtureResources('RelatedColumns');
        categoryArticle($this->category);

        $this->get(relatedUrl($this->category))->assertOk()->assertSeeInOrder(['Заголовок', 'Статус'])->assertDontSee('Заметка');
    });

    it('reject a field that is not in the list of the child section or is the key of the parent', function (string $field): void {
        useFixtureResources('RelatedColumns');
        ColumnsCategoryResource::$columns = [$field];

        Fastleo::resources()->find('categories')->listRelations();
    })->with([
        'a field that is hidden from the list' => ['excerpt'],
        'a field that does not exist' => ['nothing'],
        'the key of the parent' => ['category_id'],
    ])->throws(LogicException::class, 'Связанная таблица');
});

describe('the page of a record in the trash', function (): void {
    it('draws no tables: the parent is not a live record any more', function (): void {
        useFixtureResources('Expand');
        $article = Article::factory()->create();

        $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertOk()->assertSee('data-related="links"', false);

        $article->delete();

        $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertOk()->assertDontSee('data-related', false);
    });
});

describe('the size of the page', function (): void {
    it('is chosen in the table and kept by the search', function (): void {
        foreach (range(1, 30) as $number) {
            categoryArticle($this->category, ['title' => 'Статья '.$number]);
        }

        $this->get(relatedUrl($this->category))
            ->assertOk()
            ->assertSee('data-per-page', false)
            ->assertDontSee('x-on:change="window.location', false);

        $this->get(relatedUrl($this->category, ['per_page' => 25]))
            ->assertOk()
            ->assertSee('Статья 6')
            ->assertDontSee('Статья 5')
            ->assertSee('name="per_page" value="25"', false)
            ->assertSee('<option value="'.relatedUrl($this->category, ['per_page' => 50]).'"', false);
    });
});
