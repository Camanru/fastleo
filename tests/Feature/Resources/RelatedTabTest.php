<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Related;
use Fastleo\Settings;
use Fastleo\Support\FormSchema;
use Fastleo\Tests\Fixtures\Policies\HiddenArticlePolicy;
use Fastleo\Tests\Fixtures\RelatedTab\ArticleResource;
use Fastleo\Tests\Fixtures\RelatedTab\CategoryResource;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('RelatedTab');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
});

afterEach(function (): void {
    CategoryResource::$mode = 'ok';
    CategoryResource::$editIn = 'page';
});

it('shows the table in its own tab outside of the form and not under it', function (): void {
    Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);

    $page = $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))->assertOk();
    $html = $page->getContent();

    $page->assertSee('role="tab"', false)
        ->assertSee('data-tab-tables="stati"', false)
        ->assertSee('data-related="articles"', false);
    // Панель таблицы — после закрытия формы записи (первая форма страницы — выход в боковом меню), а не внутри: в таблице свои формы.
    $formEnd = strpos($html, '</form>', (int) strpos($html, 'name="_method"'));
    expect(substr_count($html, 'data-related="articles"'))->toBe(1)
        ->and($formEnd)->not->toBeFalse()
        ->and(strpos($html, 'data-related="articles"'))->toBeGreaterThan($formEnd);
});

it('hides the tab with the table while a record is being created', function (): void {
    $this->get(route('fastleo.resources.create', 'categories'))
        ->assertOk()
        ->assertDontSee('data-tab-tables', false)
        ->assertSee('Основное');
});

it('hides the tab when the administrator cannot open the section of the table', function (): void {
    Gate::policy(Article::class, HiddenArticlePolicy::class);

    $this->get(route('fastleo.resources.edit', ['categories', $this->category->id]))
        ->assertOk()
        ->assertDontSee('data-tab-tables', false);
});

it('serves the table of the tab on the related route: the heading is left to the tab inside the panel and shown on the record page', function (): void {
    Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);
    $url = route('fastleo.resources.related', ['categories', $this->category->id, 'articles']);

    $this->get($url.'?panel=1')->assertOk()->assertSee('Приточная')->assertDontSee('Статьи рубрики</h2>', false)->assertDontSee('tabular-nums">1</span>', false);
    $this->get($url)->assertOk()->assertSee('Статьи рубрики</h2>', false)->assertSee('tabular-nums">1</span>', false);
});

it('keeps the table of the tab out of the list shown under the form but lists it in relations', function (): void {
    $resource = Fastleo::resources()->find('categories');

    expect(array_keys($resource?->listRelations() ?? []))->toBe(['articles'])
        ->and($resource?->listRelations()['articles']->isInTab())->toBeTrue()
        ->and($resource?->listRelations()['articles']->tabKey())->toBe('stati');
});

it('rejects a tab that mixes fields and tables', function (): void {
    Tab::make('Смесь', [Text::make('title'), Related::make('articles', ArticleResource::class)]);
})->throws(InvalidArgumentException::class, 'Смесь');

it('rejects a table outside of a tab with a clear message', function (): void {
    new FormSchema([Section::make('Секция', [Related::make('articles', ArticleResource::class)])], Category::class, inResource: true);
})->throws(InvalidArgumentException::class, 'лежит не во вкладке');

it('rejects a table right in fields()', function (): void {
    new FormSchema([Text::make('title'), Related::make('articles', ArticleResource::class)], Category::class, inResource: true);
})->throws(InvalidArgumentException::class, 'лежит не во вкладке');

it('rejects a table in a tab of a settings page or an action, where no section lists it', function (): void {
    new FormSchema([Tab::make('Таблица', [Related::make('articles', ArticleResource::class)])], Category::class);
})->throws(InvalidArgumentException::class, 'работает только в разделе');

it('rejects a table in the tabs of a settings page with a clear message', function (): void {
    $settings = new class extends Settings
    {
        /**
         * Вкладка с таблицей связанных записей: в настройках её некому вывести.
         */
        public function fields(): array
        {
            return [Tabs::make([Tab::make('Таблица', [Related::make('articles', ArticleResource::class)])])];
        }
    };

    $settings->schema();
})->throws(InvalidArgumentException::class, 'работает только в разделе');

it('rejects the same table in two tabs', function (): void {
    CategoryResource::$mode = 'twice';
    Fastleo::resources()->find('categories')?->listRelations();
})->throws(LogicException::class, 'описана дважды');

it('opens the create form of a record in the window without the menu', function (): void {
    $this->get(route('fastleo.resources.create', ['articles', 'frame' => 1]))->assertOk()->assertSee('name="_frame"', false)->assertDontSee('fastleo-menu', false);
});

it('rejects two tabs with one key of the address', function (): void {
    Tabs::make([Tab::make('SEO', [Text::make('a')]), Tab::make('Seo', [Text::make('b')])]);
})->throws(InvalidArgumentException::class, '#seo');

it('rejects a table in a tab that sits in the side column', function (): void {
    CategoryResource::$mode = 'aside';
    Fastleo::resources()->find('categories')?->listRelations();
})->throws(LogicException::class, 'боковой колонке');

it('rejects a table in tabs that sit inside a section', function (): void {
    CategoryResource::$mode = 'nested';
    Fastleo::resources()->find('categories')?->listRelations();
})->throws(LogicException::class, 'верхнего уровня');

it('rejects the same relation in a tab and in relations()', function (): void {
    CategoryResource::$mode = 'duplicate';
    Fastleo::resources()->find('categories')?->listRelations();
})->throws(LogicException::class, 'описана дважды');

it('refuses an unknown place to edit in', function (): void {
    Related::make('articles', ArticleResource::class)->editIn('popup');
})->throws(InvalidArgumentException::class, 'popup');

it('gives the edit and add buttons of a table the address for the window when it edits in a modal', function (): void {
    CategoryResource::$editIn = 'modal';
    $article = Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);

    $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
        ->assertOk()
        ->assertSee('data-record-modal="'.route('fastleo.resources.edit', ['articles', $article->id, 'frame' => 1]).'"', false)
        ->assertSee('data-record-modal="'.route('fastleo.resources.edit', ['articles', $article->id, 'frame' => 1]).'" title="'.__('fastleo::resources.edit').'"', false)
        ->assertDontSee('data-record-view="'.route('fastleo.resources.edit', ['articles', $article->id, 'frame' => 1]).'"', false)
        ->assertSee('data-record-modal="'.e(route('fastleo.resources.create', ['resource' => 'articles', 'prefill' => ['category_id' => (string) $this->category->id], 'frame' => 1])).'"', false);
});

it('keeps plain links when the table edits on the page', function (): void {
    config(['fastleo.record_view' => 'page']);
    Article::factory()->create(['category_id' => $this->category->id]);

    $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))
        ->assertOk()
        ->assertDontSee('data-record-modal', false);
});

it('shows the form of a record in the frame layout with a save button only', function (): void {
    $article = Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);

    $this->get(route('fastleo.resources.edit', ['articles', $article->id, 'frame' => 1]))
        ->assertOk()
        ->assertSee('name="_frame"', false)
        ->assertSee('Приточная')
        ->assertDontSee('fastleo-menu', false)
        ->assertDontSee('Сохранить и выйти')
        ->assertDontSee('data-related=', false);
});

it('tells the window the record is saved and does not render the form again', function (): void {
    $article = Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), ['_frame' => '1', 'title' => 'Новая', 'status' => 'draft', 'category_id' => $this->category->id])
        ->assertOk()->assertSee('fastleo:saved', false)->assertDontSee('name="_frame"', false);
    expect($article->refresh()->title)->toBe('Новая');
});

it('creates a record from the window and reports it saved', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), ['_frame' => '1', 'title' => 'Из окна', 'status' => 'draft', 'category_id' => $this->category->id])
        ->assertOk()->assertSee('fastleo:saved', false);
    expect(Article::query()->where('title', 'Из окна')->where('category_id', $this->category->id)->exists())->toBeTrue();
});

it('keeps the window on validation errors', function (): void {
    $article = Article::factory()->create(['title' => 'Приточная']);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), ['_frame' => '1', 'title' => '', 'status' => 'draft', 'category_id' => $this->category->id])
        ->assertRedirect(route('fastleo.resources.edit', ['articles', $article->id, 'frame' => '1']))
        ->assertSessionHasErrors('title');
    expect($article->refresh()->title)->toBe('Приточная');
});

it('leaves the tab with a table out of the form in the window', function (): void {
    $url = route('fastleo.resources.edit', ['categories', $this->category->id]);

    $this->get($url)->assertOk()->assertSee('data-tab-tables="stati"', false)->assertSee('Статьи');
    $this->get($url.'?frame=1')->assertOk()->assertSee('Основное')->assertDontSee('data-tab-tables', false)->assertDontSee('Статьи')->assertDontSee('data-related=', false);
});

it('shows the table of a tab with its heading on the record page and none in the window', function (): void {
    Article::factory()->create(['category_id' => $this->category->id]);
    $url = route('fastleo.resources.show', ['categories', $this->category->id]);

    $this->get($url)->assertOk()->assertSee('data-related="articles"', false);
    $this->get($url.'?frame=1')->assertOk()->assertDontSee('data-related=', false);
});

it('keeps the input of an action form of a tab table for the table to show again', function (): void {
    $article = Article::factory()->create(['category_id' => $this->category->id]);

    $this->post(route('fastleo.resources.action', ['articles', 'stamp-articles']), ['ids' => [(string) $article->id], 'data' => ['code' => 'ошибка-ввода'], '_related' => 'articles', '_related_parent' => (string) $this->category->id])
        ->assertRedirect();

    $this->get(route('fastleo.resources.related', ['categories', $this->category->id, 'articles']))->assertOk()->assertSee('ошибка-ввода');
});

it('shows the page of a record in the frame layout without menu, buttons and related tables', function (): void {
    $article = Article::factory()->create(['title' => 'Приточная', 'category_id' => $this->category->id]);

    $this->get(route('fastleo.resources.show', ['articles', $article->id, 'frame' => 1]))
        ->assertOk()
        ->assertSee('Приточная')
        ->assertDontSee('fastleo-menu', false)
        ->assertDontSee('data-related=', false);

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('fastleo-menu', false);
});

it('does not let the frame flag bypass authorization', function (): void {
    Gate::policy(Article::class, HiddenArticlePolicy::class);
    $article = Article::factory()->create();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id, 'frame' => 1]))->assertForbidden();
});
