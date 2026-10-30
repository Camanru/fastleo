<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Password;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('EmptyValues');
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('writing', function (): void {
    it('writes emptyValue() instead of null when the field is left blank', function (): void {
        $this->post(route('fastleo.resources.store', 'empty-values'), ['title' => '', 'type' => '', 'views' => '', 'phone' => ''])
            ->assertSessionHasNoErrors();

        $article = Article::query()->sole();

        expect($article->getRawOriginal('title'))->toBe('')
            ->and($article->type)->toBe('none')
            ->and($article->views)->toBe(0)
            ->and($article->phone)->toBeNull();
    });

    it('writes emptyValue() into the fields of nested records', function (): void {
        $this->post(route('fastleo.resources.store', 'empty-values'), [
            'title' => '', 'type' => '', 'views' => '', 'links' => [['title' => '', 'url' => 'https://example.com']],
        ])->assertSessionHasNoErrors();

        expect(Article::query()->sole()->links()->sole()->getRawOriginal('title'))->toBe('');
    });

    it('keeps a filled value and still validates it against the options', function (): void {
        $this->post(route('fastleo.resources.store', 'empty-values'), ['title' => '<p>Текст</p>', 'type' => 'promo', 'views' => '5'])->assertSessionHasNoErrors();
        $this->post(route('fastleo.resources.store', 'empty-values'), ['title' => '', 'type' => 'hacked', 'views' => ''])->assertSessionHasErrors('type');

        expect(Article::query()->count())->toBe(1)
            ->and(Article::query()->sole()->type)->toBe('promo');
    });

    it('does not make a required field optional', function (): void {
        $required = Select::make('type')->options(['a' => 'A'])->required()->emptyValue('none');
        $optional = Select::make('type')->options(['a' => 'A'])->emptyValue('none');

        expect(validator(['type' => null], $required->getRules(null))->fails())->toBeTrue()
            ->and(validator(['type' => 'none'], $required->getRules(null))->fails())->toBeTrue()
            ->and(validator(['type' => 'none'], $optional->getRules(null))->passes())->toBeTrue();
    });
});

describe('showing', function (): void {
    it('shows the stored emptyValue() as the empty option of the form, not as an extra choice', function (): void {
        $article = Article::factory()->create(['type' => 'none', 'views' => 0]);

        $this->get(route('fastleo.resources.edit', ['empty-values', $article->id]))
            ->assertOk()
            ->assertSee('Без вида')
            ->assertDontSee('<option value="none"', false);
    });

    it('treats the stored emptyValue() as empty in the list, the export and the import', function (): void {
        $field = Select::make('type')->options(['news' => 'Новость'])->placeholder('Без вида')->emptyValue('none');
        $record = new Article(['type' => 'none']);

        expect($field->display($record))->toBeNull()
            ->and($field->exportValue($record))->toBeNull()
            ->and($field->importValue(null))->toBeNull()
            ->and($field->importValue('Без вида'))->toBeNull()
            ->and($field->importValue('Новость'))->toBe('news');
    });
});

describe('fields that do not take it', function (): void {
    it('refuses emptyValue() where an empty value has a meaning of its own', function (object $field): void {
        expect(fn () => $field->emptyValue(0))->toThrow(LogicException::class, 'emptyValue()');
    })->with([
        'checkbox' => fn (): Checkbox => Checkbox::make('flag'),
        'password' => fn (): Password => Password::make('secret'),
        'gallery' => fn (): Gallery => Gallery::make('photos'),
        'tags' => fn (): Tags => Tags::make('tags'),
        'repeater' => fn (): Repeater => Repeater::make('items')->fields([Text::make('a')]),
        'multiple' => fn (): Select => Select::make('days')->options(['a' => 'A'])->multiple(),
        'translatable' => fn (): Text => Text::make('title')->translatable(),
    ]);

    it('refuses it when multiple() or translatable() come after it', function (): void {
        $multiple = Select::make('days')->options(['a' => 'A'])->emptyValue(0)->multiple();
        $translatable = Text::make('title')->emptyValue('')->translatable();

        expect(fn () => $multiple->fill(new Article, null))->toThrow(LogicException::class)
            ->and(fn () => $translatable->fill(new Article, []))->toThrow(LogicException::class);
    });

    it('refuses it in a subfield of a JSON field', function (): void {
        expect(fn (): array => Repeater::make('items')->fields([Text::make('a')->emptyValue('')])->getRules(null))->toThrow(LogicException::class, 'emptyValue()');
    });
});

describe('a relation whose empty value is 0', function (): void {
    /**
     * Статья, у которой в `category_id` лежит 0 («без категории»); в базу не пишется.
     */
    function articleWithoutCategory(): Article
    {
        $article = new Article(['category_id' => 0]);
        $article->exists = true;
        $article->syncOriginal();

        return $article;
    }

    it('treats the stored 0 as empty in the form, the list and the export', function (): void {
        $field = BelongsTo::make('category_id')->forModel(Article::class)->emptyValue(0);
        $article = articleWithoutCategory();

        expect($field->savedKeys($article))->toBe([])
            ->and($field->display($article))->toBeNull()
            ->and($field->exportValue($article))->toBeNull();
    });

    it('still refuses 0 for a required relation and takes it for an optional one', function (): void {
        $category = Category::factory()->create();
        $required = BelongsTo::make('category_id')->forModel(Article::class)->required()->emptyValue(0);
        $optional = BelongsTo::make('category_id')->forModel(Article::class)->emptyValue(0);

        expect(validator(['category_id' => 0], $required->getRules(articleWithoutCategory()))->fails())->toBeTrue()
            ->and(validator(['category_id' => 0], $optional->getRules(articleWithoutCategory()))->passes())->toBeTrue()
            ->and(validator(['category_id' => $category->id], $required->getRules(null))->passes())->toBeTrue();
    });
});

describe('a saved value outside the options in nested blocks', function (): void {
    it('is not offered in a block and is not accepted there, as before', function (): void {
        $field = Select::make('kind')->options(['a' => 'A']);
        $field->nestedIn('faq.*.');
        $item = new Article(['kind' => 'legacy']);
        $item->exists = true;
        $item->syncOriginal();

        expect($field->savedValues($item))->toBe([])
            ->and(validator(['faq' => [['kind' => 'legacy']]], ['faq.*.kind' => $field->getRules($item)['kind']])->fails())->toBeTrue();
    });
});
