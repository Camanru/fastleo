<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Support\FormSchema;
use Fastleo\Tests\Fixtures\Policies\NoUpdateArticlePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Inline');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->category = Category::factory()->create(['title' => 'Вентиляция']);
    $this->article = Article::factory()->create(['title' => 'Статья', 'views' => 10, 'excerpt' => 'Анонс', 'status' => 'draft', 'category_id' => $this->category->id]);
});

/**
 * Адрес правки поля в ячейке.
 */
function inlineUrl(Article $article, string $field): string
{
    return route('fastleo.resources.inline', ['inline-articles', $article->id, $field]);
}

describe('the cell', function (): void {
    it('draws an input, a number input and lists for the fields that may be edited in place', function (): void {
        $url = inlineUrl($this->article, 'views');

        $this->get(route('fastleo.resources.index', 'inline-articles'))
            ->assertOk()
            ->assertSee('data-inline="views"', false)
            ->assertSee('data-inline="excerpt"', false)
            ->assertSee('data-inline="status"', false)
            ->assertSee('data-inline="category_id"', false)
            ->assertSee(e(str_replace('/', '\/', $url)), false)
            ->assertSee('type="number"', false)
            ->assertSee('<option value="draft" selected>Черновик</option>', false)
            ->assertSee('<option value="'.$this->category->id.'" selected>Вентиляция</option>', false)
            ->assertSee('>—</option>', false);
    });

    it('leaves plain text without the update right, in a read-only field and in the trash', function (): void {
        $this->get(route('fastleo.resources.index', 'inline-articles'))->assertOk()->assertDontSee('data-inline="author_email"', false);
    });

    it('leaves plain text in a field that the form of the edit page does not show', function (): void {
        $this->get(route('fastleo.resources.index', 'inline-articles'))->assertOk()->assertSee('Рейтинг')->assertDontSee('data-inline="rating"', false);

        $this->article->delete();
        $this->get(route('fastleo.resources.index', ['resource' => 'inline-articles', 'trashed' => 1]))->assertOk()->assertDontSee('data-inline=', false);

        $this->article->restore();
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);
        $this->get(route('fastleo.resources.index', 'inline-articles'))->assertOk()->assertDontSee('data-inline=', false)->assertSee('Анонс');
    });

    it('does not draw a list of related records that is too long for a cell', function (): void {
        config(['fastleo.relations.preload' => 1]);
        Category::factory()->create();

        $this->get(route('fastleo.resources.index', 'inline-articles'))
            ->assertOk()
            ->assertSee('data-inline="status"', false)
            ->assertDontSee('data-inline="category_id"', false);
    });

    it('keeps a saved value that is no longer among the options', function (): void {
        Article::query()->whereKey($this->article->id)->update(['type' => 'legacy']);

        $this->get(route('fastleo.resources.index', 'inline-articles'))->assertOk()->assertSee('<option value="legacy" selected>legacy</option>', false);
    });
});

describe('the look of a cell', function (): void {
    it('paints the list of a status in the colour of the chosen badge', function (): void {
        $this->get(route('fastleo.resources.index', 'inline-articles'))
            ->assertOk()
            ->assertSee('!bg-sunken', false)
            ->assertSee('!bg-success-soft', false);
    });

    it('sends the columns counted by the query again after a save', function (): void {
        $response = $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '25'])->assertOk();

        expect($response->json('cells.views_double'))->toContain('50')
            ->and($response->json('cells'))->toHaveKey('views_double');
    });

    it('marks the cells so that the page can find them', function (): void {
        $this->get(route('fastleo.resources.index', 'inline-articles'))->assertSee('data-field="views_double"', false);
    });
});

describe('saving', function (): void {
    it('saves a number, a text, a list and a related record through the model', function (): void {
        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '25'])->assertOk()->assertJson(['value' => '25', 'display' => '25']);
        $this->patchJson(inlineUrl($this->article, 'excerpt'), ['value' => 'Новый анонс'])->assertOk()->assertJson(['value' => 'Новый анонс']);
        $this->patchJson(inlineUrl($this->article, 'status'), ['value' => 'published'])->assertOk()->assertJson(['value' => 'published', 'display' => 'Опубликовано']);

        $other = Category::factory()->create(['title' => 'Отопление']);
        $this->patchJson(inlineUrl($this->article, 'category_id'), ['value' => (string) $other->id])->assertOk()->assertJson(['value' => (string) $other->id, 'display' => 'Отопление']);

        $this->article->refresh();

        expect($this->article->views)->toBe(25)
            ->and($this->article->excerpt)->toBe('Новый анонс')
            ->and($this->article->status->value)->toBe('published')
            ->and($this->article->category_id)->toBe($other->id)
            ->and($this->article->title)->toBe('Статья');
    });

    it('writes only the edited field to the activity log', function (): void {
        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '25'])->assertOk();

        $activity = Activity::query()->where('event', 'updated')->latest('id')->first();

        expect(array_keys($activity->changes))->toBe(['views']);
    });

    it('clears an optional related record and refuses to clear a required field', function (): void {
        $this->patchJson(inlineUrl($this->article, 'category_id'), ['value' => null])->assertOk()->assertJson(['value' => '']);
        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => null])->assertStatus(422)->assertJsonStructure(['message']);

        expect($this->article->refresh()->category_id)->toBeNull()->and($this->article->views)->toBe(10);
    });

    it('answers with the first validation error and leaves the record untouched', function (string $field, mixed $value): void {
        $this->patchJson(inlineUrl($this->article, $field), ['value' => $value])->assertStatus(422)->assertJsonStructure(['message']);

        expect($this->article->refresh()->only(['views', 'excerpt', 'status', 'category_id']))
            ->toBe(['views' => 10, 'excerpt' => 'Анонс', 'status' => ArticleStatus::Draft, 'category_id' => $this->category->id]);
    })->with([
        'a negative number' => ['views', '-5'],
        'text instead of a number' => ['views', 'много'],
        'text longer than the field allows' => ['excerpt', str_repeat('я', 16)],
        'a status that does not exist' => ['status', 'archived'],
        'a related record that does not exist' => ['category_id', '99999'],
    ]);
});

describe('a refusal while saving', function (): void {
    it('explains why the database refused the value and keeps the record', function (): void {
        Article::updating(function (): never {
            throw new QueryException('sqlite', 'update articles', [], new PDOException('FOREIGN KEY constraint failed'));
        });

        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '25'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Не удалось сохранить или удалить: запись связана с другими записями или указана несуществующая связанная запись.']);

        expect($this->article->refresh()->views)->toBe(10);
    });

    it('says so when an observer of the site cancels the change', function (): void {
        Article::updating(fn (): bool => false);

        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '25'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Сайт отменил сохранение (обработчик модели вернул false). Изменения не записаны.']);

        expect($this->article->refresh()->views)->toBe(10);
    });
});

describe('a record that leaves the section after the change', function (): void {
    it('is saved, and the answer simply has no recalculated cells', function (): void {
        useFixtureResources('InlineScoped');

        $this->patchJson(route('fastleo.resources.inline', ['draft-articles', $this->article->id, 'status']), ['value' => 'published'])
            ->assertOk()
            ->assertJson(['value' => 'published', 'cells' => []]);

        expect($this->article->refresh()->status)->toBe(ArticleStatus::Published);
    });
});

describe('refusals', function (): void {
    it('refuses a field that is not editable in place, a read-only field and an unknown field', function (): void {
        $this->patchJson(inlineUrl($this->article, 'title'), ['value' => 'Взлом'])->assertNotFound();
        $this->patchJson(inlineUrl($this->article, 'author_email'), ['value' => 'a@example.com'])->assertForbidden();
        $this->patchJson(inlineUrl($this->article, 'is_published'), ['value' => '1'])->assertNotFound();

        expect($this->article->refresh()->title)->toBe('Статья');
    });

    it('refuses without the update right', function (): void {
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);

        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '25'])->assertForbidden();

        expect($this->article->refresh()->views)->toBe(10);
    });

    it('answers 404 for a record outside the section and for a wrong key', function (): void {
        $this->patchJson(route('fastleo.resources.inline', ['inline-articles', 99999, 'views']), ['value' => '1'])->assertNotFound();
        $this->patchJson(route('fastleo.resources.inline', ['inline-articles', 'abc', 'views']), ['value' => '1'])->assertNotFound();
    });

    it('refuses a request without a value, with an array, with a too long text and without a login', function (): void {
        $this->patchJson(inlineUrl($this->article, 'views'), [])->assertStatus(422);
        $this->patchJson(inlineUrl($this->article, 'excerpt'), ['value' => ['a']])->assertStatus(422);
        $this->patchJson(inlineUrl($this->article, 'excerpt'), ['value' => str_repeat('я', 1001)])->assertStatus(422);

        auth(Fastleo::GUARD)->logout();
        $this->patchJson(inlineUrl($this->article, 'views'), ['value' => '1'])->assertUnauthorized();
    });
});

describe('description', function (): void {
    it('refuses fields that cannot be edited in place', function (): void {
        Slug::make('slug')->inlineEditable();
    })->throws(LogicException::class, 'inlineEditable()');

    it('refuses a field that depends on another field or that others depend on', function (callable $fields): void {
        new FormSchema($fields());
    })->with([
        'dependsOn' => [fn (): array => [Text::make('a'), Select::make('b')->options(fn (): array => [])->dependsOn('a')->inlineEditable()]],
        'visibleWhen' => [fn (): array => [Text::make('a'), Text::make('b')->visibleWhen('a', 'x')->inlineEditable()]],
        'multiple' => [fn (): array => [Select::make('b')->options(['x' => 'X'])->multiple()->inlineEditable()]],
        'fromQuery' => [fn (): array => [Number::make('b')->fromQuery()->inlineEditable()]],
        'controls another field' => [fn (): array => [Select::make('a')->options(['x' => 'X'])->inlineEditable(), Text::make('b')->visibleWhen('a', 'x')]],
        'is the dependency of a list' => [fn (): array => [Enum::make('a')->enum(ArticleStatus::class)->inlineEditable(), Select::make('b')->options(fn (): array => [])->dependsOn('a')]],
    ])->throws(InvalidArgumentException::class, 'inlineEditable()');

    it('accepts independent fields', function (): void {
        $schema = new FormSchema([Text::make('a')->inlineEditable(), Number::make('b')->inlineEditable()]);

        expect($schema->field('a')?->isInlineEditable())->toBeTrue();
    });
});
