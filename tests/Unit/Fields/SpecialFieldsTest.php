<?php

declare(strict_types=1);

use Fastleo\Fields\Code;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Markdown;
use Fastleo\Fields\Password;
use Fastleo\Fields\Radio;
use Fastleo\Fields\Range;
use Fastleo\Fields\Secret;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tags;
use Fastleo\Support\OptionRule;
use Fastleo\Tests\Fixtures\Casts\ReversingCast;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Supplier;
use Workbench\App\Models\User;

it('allows a single choice only in radio', function (): void {
    expect(Radio::make('layout')->multiple(false)->isMultiple())->toBeFalse();

    Radio::make('layout')->multiple();
})->throws(LogicException::class, 'Radio «layout» выбирает один вариант.');

it('does not let a radio depend on another field because its template cannot reload the options', function (): void {
    Radio::make('layout')->dependsOn('type');
})->throws(LogicException::class, 'Radio «layout» не поддерживает dependsOn()');

it('uses 0–100 integer bounds for a range by default', function (): void {
    $range = Range::make('rating');

    expect([$range->getMin(), $range->getMax(), $range->getStep()])->toBe([0, 100, '1'])
        ->and($range->getRules(null)['rating'])->toBe(['nullable', 'integer', 'min:0', 'max:100']);
});

describe('enum', function (): void {
    it('takes options and labels from the enum', function (): void {
        $field = Enum::make('status')->enum(ArticleStatus::class);

        expect($field->getEnum())->toBe(ArticleStatus::class)
            ->and($field->getOptions())->toBe(['draft' => 'Черновик', 'review' => 'На проверке', 'published' => 'Опубликовано'])
            ->and($field->view())->toBe('fastleo::fields.select');
    });

    it('rejects a class that is not a backed enum', function (): void {
        Enum::make('status')->enum(Article::class);
    })->throws(InvalidArgumentException::class, 'не является backed enum');

    it('reports a missing enum when rules are built', function (): void {
        Enum::make('status')->getRules(null);
    })->throws(LogicException::class, 'укажите enum вариантов');
});

describe('password', function (): void {
    it('is required only when the record is created', function (): void {
        $field = Password::make('password')->required();

        expect($field->getRules(null)['password'][0])->toBe('required')
            ->and($field->getRules(User::factory()->create())['password'][0])->toBe('nullable');
    });

    it('refuses a password longer than 255 characters', function (): void {
        $rules = Password::make('password')->getRules(null);

        expect(Validator::make(['password' => str_repeat('a1', 128)], $rules)->errors()->keys())->toBe(['password'])
            ->and(Validator::make(['password' => str_repeat('a1', 20)], $rules)->fails())->toBeFalse();
    });

    it('never shows the hash', function (): void {
        $user = User::factory()->create();

        expect(Password::make('password')->hydrate($user))->toBeNull()
            ->and(Password::make('password')->formValue(null))->toBeNull()
            ->and(Password::make('password')->display($user))->toBeNull()
            ->and(Password::make('password')->isVisibleInList())->toBeFalse()
            ->and(Password::make('password')->isVisibleOnShow($user))->toBeFalse();
    });

    it('keeps spaces and leaves the model untouched for an empty value', function (): void {
        $user = new User();

        Password::make('password')->fill($user, Password::make('password')->dehydrate(''));
        expect($user->getAttributes())->toBe([]);

        Password::make('password')->fill($user, Password::make('password')->dehydrate(' pass '));
        expect(password_verify(' pass ', (string) $user->getAttributes()['password']))->toBeTrue();
    });

    it('takes a password of spaces only for an empty one', function (string $spaces): void {
        $user = new User();

        expect(Password::make('password')->dehydrate($spaces))->toBeNull();

        Password::make('password')->fill($user, Password::make('password')->dehydrate($spaces));
        expect($user->getAttributes())->toBe([]);
    })->with(['spaces' => '        ', 'tabs and a line break' => " \t\n "]);
});

describe('secret encryption', function (): void {
    it('writes the value of an encrypted column and keeps it encrypted in the database', function (): void {
        $supplier = new Supplier();

        Secret::make('api_key')->fill($supplier, 'sk_live_123');

        expect($supplier->api_key)->toBe('sk_live_123')
            ->and($supplier->getAttributes()['api_key'])->not->toBe('sk_live_123')
            ->and(Crypt::decryptString($supplier->getAttributes()['api_key']))->toBe('sk_live_123');
    });

    it('refuses to write a secret into a column that is not encrypted', function (): void {
        Secret::make('excerpt')->fill(new Article(), 'sk_live_123');
    })->throws(LogicException::class, 'Поле «excerpt» (Secret) хранит секрет');

    it('does not complain about an empty secret on a column that is not encrypted', function (): void {
        $article = new Article();

        Secret::make('excerpt')->fill($article, null);

        expect($article->getAttributes())->toBe([]);
    });

    it('takes an encrypted array cast, a cast class of the site and a write mutator for encryption', function (): void {
        $arrayCast = new class extends Model
        {
            protected function casts(): array
            {
                return ['token' => 'encrypted:array'];
            }
        };
        $classCast = new class extends Model
        {
            protected function casts(): array
            {
                return ['token' => AsEncryptedArrayObject::class];
            }
        };
        $mutator = new class extends Model
        {
            protected function token(): Attribute
            {
                return Attribute::set(fn (string $value): string => strrev($value));
            }
        };
        $plain = new class extends Model {};

        expect(Secret::make('token')->isEncryptedBy($arrayCast))->toBeTrue()
            ->and(Secret::make('token')->isEncryptedBy($classCast))->toBeTrue()
            ->and(Secret::make('token')->isEncryptedBy($mutator))->toBeTrue()
            ->and(Secret::make('token')->isEncryptedBy($plain))->toBeFalse();
    });
});

describe('secret cast', function (): void {
    it('does not take an enum cast or a Laravel cast that stores plain text for encryption, but takes a cast class of the site', function (string $cast, bool $encrypts): void {
        $model = new class($cast) extends Model
        {
            public function __construct(private readonly string $cast = '')
            {
                parent::__construct();
            }

            protected function casts(): array
            {
                return ['token' => $this->cast];
            }
        };

        expect(Secret::make('token')->isEncryptedBy($model))->toBe($encrypts);
    })->with([
        'an enum' => [ArticleStatus::class, false],
        'an array object that is not encrypted' => [AsArrayObject::class, false],
        'a collection that is not encrypted' => [AsCollection::class, false],
        'a cast with an argument that is not a cast' => ['stdClass:x', false],
        'an unknown class' => ['App\\Casts\\Nothing', false],
        'an encrypted array object' => [AsEncryptedArrayObject::class, true],
        'an encrypted collection' => [AsEncryptedCollection::class, true],
        'the cast class of the site' => [ReversingCast::class, true],
        'the cast class of the site with an argument' => [ReversingCast::class.':x', true],
    ]);
});

describe('select search', function (): void {
    it('searches options whose labels come from a closure as numbers', function (): void {
        $field = Select::make('rating')->options(fn (): array => [1 => 100, 2 => 250]);

        expect($field->searchOptions('25'))->toBe([['value' => '2', 'label' => '250']]);
    });
});

describe('slug', function (): void {
    it('does not accept a trailing line break that the unique check would not see', function (): void {
        $rules = Slug::make('slug')->unique(false)->getRules(null);

        expect(Validator::make(['slug' => "abc\n"], $rules)->fails())->toBeTrue()
            ->and(Validator::make(['slug' => 'abc'], $rules)->fails())->toBeFalse();
    });

    it('transliterates russian text like Laravel', function (string $text, string $slug): void {
        expect(Slug::slugify($text))->toBe($slug);
    })->with([
        ['О компании', 'o-kompanii'],
        ['Ёжик в тумане — 2026!', 'yozhik-v-tumane-2026'],
        ['Щука и Юла', 'schuka-i-yula'],
    ]);

    it('checks the format and adds the rule of uniqueness in the model table', function (): void {
        $rules = Slug::make('slug')->forModel(Article::class)->getRules(null)['slug'];

        expect($rules)->toContain('regex:'.Slug::PATTERN)
            ->and(end($rules))->toBeInstanceOf(OptionRule::class);
    });

    it('can skip the uniqueness check', function (): void {
        expect(Slug::make('slug')->unique(false)->getRules(null)['slug'])->toBe(['nullable', 'string', 'max:255', 'regex:'.Slug::PATTERN]);
    });

    it('needs a model for the uniqueness rule', function (): void {
        Slug::make('slug')->getRules(null);
    })->throws(LogicException::class, 'не привязано к модели');

    it('leaves a filled slug or a slug without source alone', function (): void {
        $filled = new Category(['title' => 'Новости']);
        $filled->setAttribute('code', 'news');
        Slug::make('code')->from('title')->beforeSave($filled);

        $empty = new Category(['title' => 'Новости']);
        Slug::make('code')->beforeSave($empty);
        Slug::make('code')->from('missing')->beforeSave($empty);

        expect($filled->code)->toBe('news')->and($empty->code)->toBeNull();
    });
});

describe('tags', function (): void {
    it('reads tags from an array or a json string', function (): void {
        expect(Tags::make('keywords')->hydrate(new Article(['keywords' => ['a', 1]])))->toBe(['a', '1'])
            ->and(Tags::make('keywords')->hydrate(new Category()->setRawAttributes(['keywords' => '["x"]'])))->toBe(['x'])
            ->and(Tags::make('keywords')->formValue(null))->toBe([])
            ->and(Tags::make('keywords')->display(new Article(['keywords' => ['a', 'b']])))->toBe('a, b')
            ->and(Tags::make('keywords')->display(new Article()))->toBeNull();
    });

    it('builds rules for the list and each tag', function (): void {
        expect(Tags::make('keywords')->maxTagLength(20)->getRules(null))->toBe([
            'keywords' => ['nullable', 'array', 'max:100'],
            'keywords.*' => ['nullable', 'string', 'max:20'],
        ])->and(Tags::make('keywords')->suggestions(['a'])->getSuggestions())->toBe(['a']);
    });

    it('limits the number of tags', function (): void {
        $rules = fn (int $count): bool => validator(['keywords' => array_map(fn (int $i): string => 'tag'.$i, range(1, $count))], Tags::make('keywords')->getRules(null))->passes();

        expect($rules(Tags::MAX_TAGS))->toBeTrue()
            ->and($rules(Tags::MAX_TAGS + 1))->toBeFalse();
    });

    it('needs an array cast in the model', function (): void {
        Tags::make('title')->fill(new Category(), ['a']);
    })->throws(LogicException::class, 'добавьте колонке каст');
});

it('shows code as escaped text and markdown as html', function (): void {
    $article = new Article(['head_code' => '<b>x</b>', 'body' => '**x** '.str_repeat('я', 120)]);
    $html = fn (mixed $value): string => $value instanceof Htmlable ? $value->toHtml() : (string) $value;

    expect($html(Code::make('head_code')->display($article)))->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->and(Code::make('head_code')->listDisplay($article))->toBe('<b>x</b>')
        ->and(Code::make('head_code')->display(new Article()))->toBeNull()
        ->and(Code::make('head_code')->listDisplay(new Article()))->toBeNull()
        ->and($html(Markdown::make('body')->display($article)))->toStartWith('<div class="fastleo-prose"><p><strong>x</strong>')
        ->and(mb_strlen((string) Markdown::make('body')->listDisplay($article)))->toBe(103)
        ->and(Markdown::make('body')->listDisplay(new Article()))->toBeNull()
        ->and(Markdown::make('body')->formatUsing(fn (): string => 'своё')->display($article))->toBe('своё')
        ->and(Markdown::render(null)->toHtml())->toBe('')
        ->and(Markdown::render('')->toHtml())->toBe('');
});
