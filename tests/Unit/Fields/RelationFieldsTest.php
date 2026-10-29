<?php

declare(strict_types=1);

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Field;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\Password;
use Fastleo\Fields\Range;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Support\FormSchema;
use Fastleo\Tests\Fixtures\Models\Assignment;
use Illuminate\Database\Eloquent\Collection;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

describe('belongs to', function (): void {
    it('takes the relation from the column name and the title from fillable columns', function (): void {
        expect(BelongsTo::make('category_id')->forModel(Article::class)->getTitleColumn())->toBe('title')
            ->and(BelongsTo::make('user_id')->forModel(Assignment::class)->getTitleColumn())->toBe('name')
            ->and(BelongsTo::make('ghost_id')->forModel(Assignment::class)->getTitleColumn())->toBe('id')
            ->and(BelongsTo::make('category_ref')->relation('section')->forModel(Assignment::class)->titleColumn('code')->getTitleColumn())->toBe('code')
            ->and(BelongsTo::make('category_id')->getRelationName())->toBe('category')
            ->and(BelongsTo::make('category_id')->eagerLoad())->toBe(['category'])
            ->and(BelongsTo::make('category_id')->isMultiple())->toBeFalse();
    });

    it('reports a model without the relation method', function (string $name, ?string $relation): void {
        $field = BelongsTo::make($name)->forModel(Assignment::class);

        ($relation === null ? $field : $field->relation($relation))->relatedModel();
    })->throws(LogicException::class, 'нет метода связи')->with([
        'no method' => ['author_id', null],
        'not a relation' => ['label_id', 'label'],
    ]);

    it('reports a field named not by the foreign key', function (): void {
        BelongsTo::make('section_id')->forModel(Assignment::class)->relatedModel();
    })->throws(LogicException::class, 'Назовите поле по колонке: BelongsTo::make(\'category_ref\')');

    it('rejects a title column that is not a column name', function (): void {
        BelongsTo::make('category_id')->titleColumn('title; drop');
    })->throws(InvalidArgumentException::class);

    it('casts the key and shows the related title or the raw key', function (): void {
        $field = BelongsTo::make('category_id')->forModel(Article::class);
        $article = new Article(['category_id' => 7]);
        $article->setRelation('category', new Category(['title' => 'Новости']));

        expect($field->dehydrate('7'))->toBe(7)
            ->and($field->dehydrate(''))->toBeNull()
            ->and($field->hydrate($article))->toBe('7')
            ->and($field->hydrate(new Article))->toBeNull()
            ->and($field->display($article))->toBe('Новости')
            ->and($field->display(new Article(['category_id' => 9])->setRelation('category', null)))->toBe('9')
            ->and($field->formatUsing(fn (mixed $value): string => '№'.$value)->display($article))->toBe('№7')
            ->and($field->optionsFor([]))->toBe([]);
    });
});

describe('belongs to many', function (): void {
    it('cleans keys and reads the related records', function (): void {
        $field = BelongsToMany::make('topics')->forModel(Article::class);
        $article = (new Article)->setRelation('topics', new Collection([new Topic(['title' => 'А']), new Topic(['title' => 'Б'])]));

        expect($field->dehydrate(['3', '', '3', 5, ['x']]))->toBe([3, 5])
            ->and($field->dehydrate('3'))->toBe([])
            ->and($field->formValue(null))->toBe([])
            ->and($field->default([1, 'x', null])->formValue(null))->toBe(['1', 'x'])
            ->and($field->display($article))->toBe('А, Б')
            ->and($field->display((new Article)->setRelation('topics', new Collection)))->toBeNull()
            ->and($field->hydrate((new Article)->setRelation('topics', new Collection)))->toBe([])
            ->and($field->writesAttribute())->toBeFalse()
            ->and($field->isMultiple())->toBeTrue()
            ->and($field->isVisibleInList())->toBeFalse()
            ->and($field->eagerLoad())->toBe(['topics'])
            ->and($field->getTitleColumn())->toBe('title');
    });

    it('rejects visibility that depends on the block record', function (Field $inner): void {
        HasMany::make('links')->fields([$inner])->forModel(Article::class)->schema();
    })->throws(LogicException::class, 'правила общие для всех блоков')->with([
        'hide on create' => fn (): Text => Text::make('url')->hideOnCreate(),
        'hide on edit' => fn (): Text => Text::make('url')->hideOnEdit(),
        'hidden by a closure' => fn (): Text => Text::make('url')->hidden(fn (): bool => false),
    ]);

    it('knows what an untouched field sends', function (): void {
        expect(Toggle::make('is_active')->default(true)->untouchedValue())->toBeTrue()
            ->and(Toggle::make('is_active')->untouchedValue())->toBeFalse()
            ->and(Range::make('rating')->min(1)->untouchedValue())->toBe(1)
            ->and(Text::make('title')->untouchedValue())->toBeNull();
    });

    it('rejects a visibility condition on a field with several values', function (Field $controlling): void {
        new FormSchema([$controlling, Text::make('promo')->visibleWhen($controlling->name, 'x')]);
    })->throws(InvalidArgumentException::class, 'несколько значений')->with([
        'multiple select' => fn (): Select => Select::make('tags')->options(['x' => 'X'])->multiple(),
        'tags' => fn (): Tags => Tags::make('keywords'),
        'belongs to many' => fn (): BelongsToMany => BelongsToMany::make('topics'),
    ]);

    it('reports a model without the relation', function (): void {
        BelongsToMany::make('category')->forModel(Article::class)->relatedModel();
    })->throws(LogicException::class, 'возвращающего BelongsToMany');
});

describe('nested', function (): void {
    it('allows at most 200 blocks unless the site sets its own limit', function (): void {
        $links = HasMany::make('links')->fields([Text::make('title')])->forModel(Article::class);

        expect($links->getMax())->toBe(200)
            ->and($links->getRules(null)['links'])->toContain('max:200')
            ->and($links->max(500)->getMax())->toBe(500);
    });

    it('prefixes the block rules and labels', function (): void {
        $field = HasMany::make('links', 'Ссылки')->fields([Text::make('title', 'Название')->required()])->min(1)->max(3)->forModel(Article::class);

        expect($field->getRules(null))->toBe([
            'links' => ['required', 'array', 'min:1', 'max:3'],
            'links.*.id' => ['nullable', 'integer'],
            'links.*.title' => ['required', 'string', 'max:255'],
        ])->and($field->validationAttributes())->toBe([
            'links' => 'Ссылки', 'links.*' => 'Ссылки', 'links.*.title' => 'Название', 'links.*.title.*' => 'Название',
        ])->and([$field->getMin(), $field->getMax(), $field->getOrderColumn(), $field->isSingle()])->toBe([1, 3, null, false]);

        $one = HasOne::make('banner')->fields([Text::make('title')])->forModel(Article::class);

        expect(array_keys($one->getRules(null)))->toBe(['banner', 'banner.title'])
            ->and($one->dehydrate(['title' => ' Скидки ', 'extra' => 'x']))->toBe([['id' => null, 'values' => ['title' => 'Скидки']]])
            ->and($one->formValue(null))->toBeEmpty();
    });

    it('allows only simple fields inside blocks', function (Field $inner): void {
        HasMany::make('links')->fields([$inner])->forModel(Article::class)->schema();
    })->throws(LogicException::class, 'нельзя использовать внутри блоков')->with([
        'relation' => fn (): BelongsTo => BelongsTo::make('article_id'),
        'slug' => fn (): Slug => Slug::make('slug'),
        'password' => fn (): Password => Password::make('password'),
        'nested' => fn (): HasMany => HasMany::make('rows'),
        'json blocks' => fn (): Repeater => Repeater::make('rows'),
        'translatable' => fn (): Text => Text::make('title')->translatable(),
    ]);

    it('reports a model without the relation', function (): void {
        HasOne::make('links')->forModel(Article::class)->schema();
    })->throws(LogicException::class, 'возвращающего HasOne');

    it('rejects an order column that is not a column name', function (): void {
        HasMany::make('links')->orderColumn('sort desc');
    })->throws(InvalidArgumentException::class);

    it('requires fillable block fields', function (): void {
        $article = Article::factory()->create();

        HasMany::make('links')->fields([Text::make('sort')])->forModel(Article::class)
            ->afterSave($article, [['id' => null, 'values' => ['sort' => '5']]]);
    })->throws(LogicException::class, 'не разрешено моделью');
});

describe('conditions', function (): void {
    it('turns the condition into an exclude rule with form values', function (): void {
        $field = Text::make('promo')->visibleWhen('status', [ArticleStatus::Published, 'a,"b"', true]);

        expect($field->getVisibleWhen())->toBe(['field' => 'status', 'values' => ['published', 'a,"b"', '1']])
            ->and($field->getRules(null)['promo'][0])->toBe('exclude_unless:status,"published","a,""b""","1"')
            ->and($field->nestedIn('links.*.')->getRules(null)['promo'][0])->toStartWith('exclude_unless:links.*.status,');
    });

    it('rejects a condition field that is not a column name', function (): void {
        Text::make('promo')->visibleWhen('a.b', 'x');
    })->throws(InvalidArgumentException::class);

    it('needs the controlling field of a block field to be in the same block and open to every admin', function (array $fields): void {
        HasMany::make('links')->fields($fields)->forModel(Article::class)->schema();
    })->throws(LogicException::class, 'visibleWhen(\'title\')')->with([
        'outside the block' => fn (): array => [Text::make('url')->visibleWhen('title', 'x')],
        'read only' => fn (): array => [Text::make('title')->readonly(), Text::make('url')->visibleWhen('title', 'x')],
        'hidden for some admins' => fn (): array => [Text::make('title')->hidden(false), Text::make('url')->visibleWhen('title', 'x')],
    ]);

    it('accepts a block field that depends on another field of the same block', function (): void {
        $schema = HasMany::make('links')->fields([Text::make('title'), Text::make('url')->visibleWhen('title', 'x')])->forModel(Article::class)->schema();

        expect($schema->fields())->toHaveCount(2);
    });

    it('does not allow dependent lists inside blocks', function (): void {
        HasMany::make('links')->fields([Select::make('url')->options([])->dependsOn('title')])->forModel(Article::class)->schema();
    })->throws(LogicException::class, 'зависимых списков');

    it('rejects a select dependency that is not a column name', function (): void {
        Select::make('a')->dependsOn('x y');
    })->throws(InvalidArgumentException::class);

    it('rejects a relation dependency that is not a column name', function (): void {
        BelongsTo::make('category_id')->dependsOn('x y', fn (): null => null);
    })->throws(InvalidArgumentException::class);
});
