<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\MorphTo;
use Fastleo\Support\FormSchema;
use Fastleo\Tests\Fixtures\Models\GuardedRemark;
use Fastleo\Tests\Fixtures\Morph\ArticleResource;
use Fastleo\Tests\Fixtures\Morph\ProductResource;
use Fastleo\Tests\Fixtures\Morph\RemarkResource;
use Fastleo\Tests\Fixtures\Policies\NoListProductPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Workbench\App\Models\Article;
use Workbench\App\Models\Product;
use Workbench\App\Models\Remark;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

afterEach(function (): void {
    ProductResource::$closed = false;
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
    RemarkResource::$variant = 'default';
});

/**
 * Данные формы заметки.
 *
 * @param  array<string, mixed>  $subject
 * @return array<string, mixed>
 */
function remarkForm(array $subject, string $body = 'Заметка'): array
{
    return ['body' => $body, 'subject' => $subject];
}

/**
 * Заметка с записанным вручную типом и ключом (так хранится то, чего поле не создавало).
 */
function remarkOf(string $type, int|string $id, string $body = 'Старая'): Remark
{
    return Remark::query()->forceCreate(['body' => $body, 'subject_type' => $type, 'subject_id' => $id]);
}

/**
 * Заметка без объекта.
 */
function bareRemark(): Remark
{
    return Remark::query()->forceCreate(['body' => 'Без объекта']);
}

describe('saving', function (): void {
    it('saves the section and the record of the chosen object', function (): void {
        $article = Article::factory()->create();

        $this->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => 'articles', 'id' => (string) $article->id]))->assertSessionHasNoErrors();

        $remark = Remark::query()->sole();

        expect([$remark->subject_type, $remark->subject_id])->toBe([Article::class, $article->id]);
    });

    it('offers a product that is visible and refuses a hidden one', function (): void {
        $shown = Product::factory()->create(['is_visible' => true]);
        $hidden = Product::factory()->create(['is_visible' => false]);

        $this->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => 'products', 'id' => (string) $hidden->id]))->assertSessionHasErrors('subject');
        $this->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => 'products', 'id' => (string) $shown->id]))->assertSessionHasNoErrors();

        expect(Remark::query()->sole()->subject_type)->toBe(Product::class);
    });

    it('moves the record to another section and clears it when both parts are empty', function (): void {
        $article = Article::factory()->create();
        $product = Product::factory()->create(['is_visible' => true]);
        $remark = remarkOf(Article::class, $article->id);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => 'products', 'id' => (string) $product->id]))->assertSessionHasNoErrors();

        expect([$remark->fresh()?->subject_type, $remark->fresh()?->subject_id])->toBe([Product::class, $product->id]);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => '', 'id' => '']))->assertSessionHasNoErrors();

        expect([$remark->fresh()?->subject_type, $remark->fresh()?->subject_id])->toBe([null, null]);
    });

    it('asks for both parts and refuses a record that does not exist', function (array $subject): void {
        $remark = bareRemark();

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm($subject))->assertSessionHasErrors('subject');

        expect($remark->fresh()?->subject_type)->toBeNull();
    })->with([
        'only the section' => [['type' => 'articles', 'id' => '']],
        'only the record' => [['type' => '', 'id' => '5']],
        'no such record' => [['type' => 'articles', 'id' => '99999']],
    ]);

    it('leaves the object alone when the form did not send it', function (): void {
        $article = Article::factory()->create();
        $remark = remarkOf(Article::class, $article->id);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), ['body' => 'Новый текст'])->assertSessionHasNoErrors();

        expect([$remark->fresh()?->body, $remark->fresh()?->subject_id])->toBe(['Новый текст', $article->id]);
    });

    it('writes the alias of the morph map and knows the records saved under the class name', function (): void {
        Relation::morphMap(['art' => Article::class]);
        $article = Article::factory()->create(['title' => 'Приточная']);
        $old = remarkOf(Article::class, $article->id);

        $this->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => 'articles', 'id' => (string) $article->id]))->assertSessionHasNoErrors();

        expect(Remark::query()->latest('id')->first()?->subject_type)->toBe('art');
        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Статья: Приточная');
        $this->get(route('fastleo.resources.edit', ['remarks', $old->id]))->assertOk()->assertSee('name="subject[type]"', false);
    });

    it('does not write the columns again while the pair stays the same', function (): void {
        Relation::morphMap(['art' => Article::class]);
        $article = Article::factory()->create();
        $remark = remarkOf(Article::class, $article->id);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => 'articles', 'id' => (string) $article->id], 'Правка'))->assertSessionHasNoErrors();

        expect([$remark->fresh()?->body, $remark->fresh()?->subject_type])->toBe(['Правка', Article::class]);
    });

    it('does not fall over when the site requires a morph map that lacks one of the sections', function (): void {
        Relation::requireMorphMap();
        Relation::morphMap(['art' => Article::class], false);
        $product = Product::factory()->create(['is_visible' => true]);
        remarkOf(Product::class, $product->id);
        remarkOf('App\\Models\\Gone', 3);

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Товар')->assertSee('Gone #3');
    });

    it('keeps a stored object that left the variants, but not a new one like it', function (): void {
        $hidden = Product::factory()->create(['is_visible' => false]);
        $other = Product::factory()->create(['is_visible' => false]);
        $remark = remarkOf(Product::class, $hidden->id);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => 'products', 'id' => (string) $hidden->id], 'Правка'))->assertSessionHasNoErrors();
        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => 'products', 'id' => (string) $other->id]))->assertSessionHasErrors('subject');

        expect([$remark->fresh()?->body, $remark->fresh()?->subject_id])->toBe(['Правка', $hidden->id]);
    });
});

describe('the form', function (): void {
    it('shows the sections, asks the server for the records and keeps the chosen one', function (): void {
        $article = Article::factory()->create(['title' => 'Приточная']);
        $remark = remarkOf(Article::class, $article->id);

        $this->get(route('fastleo.resources.edit', ['remarks', $remark->id]))
            ->assertOk()
            ->assertSee('name="subject[type]"', false)
            ->assertSee('<option value="articles" selected>Статья</option>', false)
            ->assertSee('>Товар</option>', false)
            ->assertSee(Js::from([
                'multiple' => false,
                'options' => [],
                'selected' => [['value' => (string) $article->id, 'label' => 'Приточная']],
                'url' => route('fastleo.resources.options', ['remarks', 'subject']),
                'remote' => true,
                'depends' => 'subject[type]',
                'record' => null,
            ])->toHtml(), false);
    });

    it('labels a stored record that is hidden by the narrowing with its key', function (): void {
        $hidden = Product::factory()->create(['is_visible' => false]);
        $remark = remarkOf(Product::class, $hidden->id);

        $this->get(route('fastleo.resources.edit', ['remarks', $remark->id]))->assertOk()->assertSee('\u0022label\u0022:\u0022#'.$hidden->id.'\u0022', false);
    });

    it('keeps the choice after a validation error', function (): void {
        $article = Article::factory()->create(['title' => 'Приточная']);

        $this->from(route('fastleo.resources.create', 'remarks'))
            ->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => 'articles', 'id' => (string) $article->id], ''))
            ->assertSessionHasErrors('body');

        $this->get(route('fastleo.resources.create', 'remarks'))->assertOk()->assertSee('\u0022label\u0022:\u0022Приточная\u0022', false)->assertSee('selected>Статья', false);
    });

    it('does not tell the title of a record that the admin sent by hand', function (): void {
        $product = Product::factory()->create(['title' => 'Секретный', 'is_visible' => false]);
        Gate::policy(Product::class, NoListProductPolicy::class);

        $this->from(route('fastleo.resources.create', 'remarks'))
            ->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => 'products', 'id' => (string) $product->id], ''))
            ->assertSessionHasErrors();

        $this->get(route('fastleo.resources.create', 'remarks'))->assertOk()->assertDontSee('Секретный');
    });

    it('prints a stored value that cannot be chosen as text and does not send it', function (string $class, string $text): void {
        $remark = remarkOf($class, 5);

        $this->get(route('fastleo.resources.edit', ['remarks', $remark->id]))
            ->assertOk()
            ->assertSee($text)
            ->assertDontSee('name="subject[type]"', false);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), ['body' => 'Правка'])->assertSessionHasNoErrors();

        expect([$remark->fresh()?->body, $remark->fresh()?->subject_type, $remark->fresh()?->subject_id])->toBe(['Правка', $class, 5]);
    })->with([
        'a class that is gone' => ['App\\Models\\Gone', 'Gone #5'],
        'a model that is not in the types' => [Remark::class, 'Remark #5'],
    ]);

    it('prints a stored value of a section that is closed to the admin', function (): void {
        $product = Product::factory()->create(['title' => 'Секретный']);
        $remark = remarkOf(Product::class, $product->id);
        Gate::policy(Product::class, NoListProductPolicy::class);

        $this->get(route('fastleo.resources.edit', ['remarks', $remark->id]))->assertOk()->assertSee('Товар #'.$product->id)->assertDontSee('Секретный');
    });

    it('does not offer a closed section', function (): void {
        Gate::policy(Product::class, NoListProductPolicy::class);

        $this->get(route('fastleo.resources.create', 'remarks'))->assertOk()->assertSee('value="articles"', false)->assertDontSee('value="products"', false);
    });
});

describe('variants of the field', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Morph');
    });

    it('asks for the object when the field is required', function (): void {
        RemarkResource::$variant = 'required';
        $remark = remarkOf('App\\Models\\Gone', 5);

        $this->post(route('fastleo.resources.store', 'remarks'), ['body' => 'Заметка'])->assertSessionHasErrors('subject');
        $this->post(route('fastleo.resources.store', 'remarks'), remarkForm(['type' => '', 'id' => '']))->assertSessionHasErrors('subject');
        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), ['body' => 'Правка'])->assertSessionHasNoErrors();

        expect($remark->fresh()?->body)->toBe('Правка');
    });

    it('prints the object of a read only field and does not take a new one', function (): void {
        RemarkResource::$variant = 'readonly';
        $article = Article::factory()->create(['title' => 'Приточная']);
        $remark = remarkOf(Article::class, $article->id);

        $this->get(route('fastleo.resources.edit', ['remarks', $remark->id]))->assertOk()->assertSee('Статья: Приточная')->assertDontSee('name="subject[type]"', false);
        $this->get(route('fastleo.resources.create', 'remarks'))->assertOk()->assertSee('—');
        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => '', 'id' => '']))->assertSessionHasNoErrors();

        expect($remark->fresh()?->subject_id)->toBe($article->id);
    });

    it('formats the value with the closure of the site', function (): void {
        RemarkResource::$variant = 'format';
        $article = Article::factory()->create();
        remarkOf(Article::class, $article->id);

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('формат:{&quot;type&quot;:&quot;articles&quot;,&quot;id&quot;:&quot;'.$article->id.'&quot;}', false);
    });

    it('says that the record is not there when the database refuses the question', function (): void {
        RemarkResource::$variant = 'broken';
        $remark = remarkOf(Article::class, 5);

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Статья #5');
        $this->get(route('fastleo.resources.edit', ['remarks', $remark->id]))->assertOk();
        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => 'articles', 'id' => '6']))->assertSessionHasErrors('subject');
    });

    it('searches a section whose model has no title by the key', function (): void {
        RemarkResource::$variant = 'self';
        $remark = remarkOf('App\\Models\\Gone', 1, 'Первая заметка');

        $labels = collect($this->getJson(route('fastleo.resources.options', ['remarks', 'subject', 'depends' => 'remarks', 'q' => (string) $remark->id]))->json('options'))->pluck('label');

        expect($labels->all())->toBe(['Первая заметка']);
    });

    it('prints an object whose section the site no longer lists as a text with the section and the key', function (): void {
        RemarkResource::$variant = 'articles';
        $product = Product::factory()->create(['title' => 'Секретный']);
        remarkOf(Product::class, $product->id);

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Product #'.$product->id)->assertDontSee('Секретный');
    });
});

it('prints notes about each other without going around in circles', function (): void {
    useFixtureResources('Morph');
    RemarkResource::$variant = 'selfFirst';
    $first = remarkOf('App\\Models\\Gone', 1, 'Первая');
    $second = remarkOf(Remark::class, $first->id, 'Вторая');
    $first->forceFill(['subject_type' => Remark::class, 'subject_id' => $second->id])->save();

    $this->get(route('fastleo.resources.index', 'remarks'))->assertOk();
    $this->get(route('fastleo.resources.show', ['remarks', $first->id]))->assertOk();
});

it('refuses to be the controlling field of another one', function (): void {
    useFixtureResources('Morph');
    RemarkResource::$variant = 'controlling';

    expect(fn (): FormSchema => Fastleo::resources()->find('remarks')->schema())->toThrow(InvalidArgumentException::class);
});

it('lets the text of a note be edited in the cell while the object has a class the site does not know', function (): void {
    useFixtureResources('Morph');
    RemarkResource::$variant = 'inline';
    $remark = remarkOf('App\\Models\\Gone', 7, 'Старый текст');

    $this->patchJson(route('fastleo.resources.inline', ['remarks', $remark->id, 'body']), ['value' => 'Новый текст'])->assertOk();

    expect($remark->refresh()->only('body', 'subject_type', 'subject_id'))->toBe(['body' => 'Новый текст', 'subject_type' => 'App\\Models\\Gone', 'subject_id' => 7]);
});

it('closes a section for the field when the resource itself does not allow its list', function (): void {
    useFixtureResources('Morph');
    $product = Product::factory()->create(['title' => 'Секретный', 'is_visible' => true]);
    $remark = remarkOf(Product::class, $product->id);
    ProductResource::$closed = true;

    $this->getJson(route('fastleo.resources.options', ['remarks', 'subject', 'depends' => 'products']))->assertNotFound();
    $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), remarkForm(['type' => 'products', 'id' => (string) $product->id]))->assertSessionHasErrors('subject');
    $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Товар #'.$product->id)->assertDontSee('Секретный');
});

it('refuses to be the field another one depends on', function (): void {
    useFixtureResources('Morph');
    RemarkResource::$variant = 'depending';

    expect(fn (): FormSchema => Fastleo::resources()->find('remarks')->schema())->toThrow(InvalidArgumentException::class);
});

describe('the list and the record page', function (): void {
    it('prints the section and the title as a link to the record', function (): void {
        $article = Article::factory()->create(['title' => 'Приточная']);
        $remark = remarkOf(Article::class, $article->id);

        $this->get(route('fastleo.resources.index', 'remarks'))
            ->assertOk()
            ->assertSee('Статья: Приточная')
            ->assertSee('href="'.route('fastleo.resources.show', ['articles', $article->id]).'"', false);
        $this->get(route('fastleo.resources.show', ['remarks', $remark->id]))->assertOk()->assertSee('Статья: Приточная');
    });

    it('prints a dash when the object is not set and the key when the record is gone', function (): void {
        bareRemark();
        remarkOf(Article::class, 99999);

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Статья #99999')->assertSee('—');
    });

    it('does not tell the title of a section that is closed to the admin and gives no link', function (): void {
        $product = Product::factory()->create(['title' => 'Секретный']);
        remarkOf(Product::class, $product->id);
        Gate::policy(Product::class, NoListProductPolicy::class);

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk()->assertSee('Товар #'.$product->id)->assertDontSee('Секретный');
    });

    it('takes the records of the page with one query for each section', function (): void {
        foreach (Article::factory()->count(3)->create() as $article) {
            remarkOf(Article::class, $article->id);
        }

        foreach (Product::factory()->count(2)->create() as $product) {
            remarkOf(Product::class, $product->id);
        }

        remarkOf('App\\Models\\Gone', 3);
        DB::enableQueryLog();

        $this->get(route('fastleo.resources.index', 'remarks'))->assertOk();

        $lookups = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from [`"](articles|products)[`"] where .*[`"]id[`"] in/', $query['query']) === 1);

        expect($lookups)->toHaveCount(2);
    });

});

describe('the field description', function (): void {
    it('reports what a field cannot do', function (string $method, array $arguments): void {
        MorphTo::make('subject')->forModel(Remark::class)->types([ArticleResource::class])->{$method}(...$arguments)->resolved();
    })->throws(LogicException::class, 'MorphTo')->with([
        'sortable' => ['sortable', []],
        'searchable' => ['searchable', []],
        'inline edit' => ['inlineEditable', []],
        'translations' => ['translatable', []],
        'a value instead of empty' => ['emptyValue', [0]],
        'a computed column' => ['fromQuery', []],
    ]);

    it('reports missing types', function (): void {
        MorphTo::make('subject')->forModel(Remark::class)->resolved();
    })->throws(LogicException::class, 'не заданы разделы');

    it('reports a class that is not a section of the site', function (): void {
        MorphTo::make('subject')->forModel(Remark::class)->types([stdClass::class])->resolved();
    })->throws(LogicException::class, 'не найден среди разделов');

    it('reports a model without the relation', function (): void {
        MorphTo::make('ghost')->forModel(Remark::class)->relationInstance();
    })->throws(LogicException::class, 'возвращающего MorphTo');

    it('reads the relation under another name', function (): void {
        $field = MorphTo::make('target')->relation('subject')->forModel(Remark::class);

        expect([$field->getRelationName(), $field->typeColumn(), $field->idColumn()])->toBe(['subject', 'subject_type', 'subject_id']);
    });

    it('needs the columns to be in the fillable of the model', function (): void {
        $field = MorphTo::make('subject')->types([ArticleResource::class])->forModel(GuardedRemark::class);
        useFixtureResources('Morph');

        $field->fill(new GuardedRemark(), ['type' => 'articles', 'id' => '1']);
    })->throws(LogicException::class, 'не разрешено моделью');

    it('does not instantiate the class named in the database', function (): void {
        $remark = remarkOf('Illuminate\\Support\\Facades\\Gone', 1);
        $field = MorphTo::make('subject')->types([ArticleResource::class])->forModel(Remark::class);
        useFixtureResources('Morph');

        expect($field->hydrate($remark))->toBe(['type' => null, 'id' => '1', 'morph_class' => 'Illuminate\\Support\\Facades\\Gone'])
            ->and($field->display($remark))->toBe('Gone #1')
            ->and($field->exportValue($remark))->toBeNull();
    });

    it('takes a pair of numbers and strings and nothing else', function (): void {
        $field = MorphTo::make('subject')->forModel(Remark::class);

        expect($field->selection(['type' => ' articles ', 'id' => 5]))->toBe(['type' => 'articles', 'id' => '5'])
            ->and($field->selection(['type' => ['x'], 'id' => 1.5]))->toBe(['type' => null, 'id' => null])
            ->and($field->selection('articles:5'))->toBe(['type' => null, 'id' => null])
            ->and($field->dehydrate(['type' => 'articles', 'id' => '']))->toBe(['type' => null, 'id' => null])
            ->and($field->formValue(null))->toBe(['type' => null, 'id' => null]);
    });
});
