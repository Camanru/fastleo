<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\RichText;
use Fastleo\Support\Assets;
use Fastleo\Support\Html\DepthLimitedParser;
use Fastleo\Support\Html\RichTextSettings;
use Fastleo\Tests\Fixtures\Fields\SiteRichText;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Workbench\App\Models\Article;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('cleans the html of the editor when saving', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData([
        'content' => '<h2 onclick="x()">Цены</h2><p style="color:red;text-align:center">Текст<script>alert(1)</script></p><img src="x" onerror="alert(1)">',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(Article::query()->sole()->content)->toBe('<h2>Цены</h2><p style="text-align: center">Текст</p><img src="x" />');
});

it('keeps the whole text that passed the length check', function (): void {
    $text = '<p>'.str_repeat('Ж', 600_000).'</p><p>КОНЕЦ</p>';

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['content' => $text]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Article::query()->sole()->content)->toEndWith('<p>КОНЕЦ</p>');
});

it('saves an empty editor as null', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData(['content' => '<p><br></p>']))->assertRedirect();

    expect(Article::query()->sole()->content)->toBeNull();
});

it('does not take a text of spaces and empty tags for a filled required field', function (string $html): void {
    $field = RichText::make('content')->required();

    expect(validator(['content' => $html], $field->getRules(null))->passes())->toBeFalse();
})->with(['empty paragraph with a non-breaking space' => '<p>&nbsp;</p>', 'empty tags' => '<p><br></p><p></p>']);

it('requires only the main language of a translatable rich text', function (): void {
    config(['fastleo.locales' => ['ru', 'en']]);
    $rules = RichText::make('content')->required()->translatable()->getRules(null);

    expect(validator(['content' => ['ru' => '<p>Текст</p>', 'en' => '<p>&nbsp;</p>']], $rules)->passes())->toBeTrue()
        ->and(validator(['content' => ['ru' => '<p>&nbsp;</p>', 'en' => '<p>Text</p>']], $rules)->passes())->toBeFalse();
});

it('accepts a required field with a text or a picture', function (string $html): void {
    expect(validator(['content' => $html], RichText::make('content')->required()->getRules(null))->passes())->toBeTrue();
})->with(['text' => '<p>Текст</p>', 'picture' => '<p><img src="/uploads/a.jpg" alt=""></p>']);

it('gives the editor only the cleaned html after a validation error', function (): void {
    $this->followingRedirects()
        ->post(route('fastleo.resources.store', 'articles'), articleData(['title' => '', 'content' => '<p onclick="steal()">Текст</p><script>alert(1)</script>']))
        ->assertOk()
        ->assertSee('&lt;p&gt;Текст&lt;/p&gt;', false)
        ->assertDontSee('steal()', false)
        ->assertDontSee('alert(1)', false);
});

it('loads the editor only on forms with a rich text field', function (): void {
    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertOk()
        ->assertSee('fastleoRichText(', false)
        ->assertSee(Assets::url('hugerte.js'), false);

    $this->get(route('fastleo.resources.create', 'categories'))
        ->assertOk()
        ->assertDontSee('hugerte.js', false);
});

it('shows the formatted text on the record page and cleans it again', function (): void {
    $article = Article::factory()->create(['content' => '<p>Абзац <strong>жирно</strong></p><script>alert(1)</script>']);

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('<div class="fastleo-prose"><p>Абзац <strong>жирно</strong></p></div>', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('does not show the class attribute that the site allowed on the record page', function (): void {
    config(['fastleo.rich_text.tags' => ['span'], 'fastleo.rich_text.attributes' => ['class']]);
    $overlay = '<p><a href="https://evil.example/login"><span class="fixed inset-0 z-50 opacity-0">.</span></a></p>';
    $article = Article::factory()->create(['content' => $overlay]);

    // Сайту класс нужен на своей странице, а в панели он превратил бы ссылку в невидимую накладку.
    expect(RichText::make('content')->sanitize($overlay))->toContain('class="fixed inset-0 z-50 opacity-0"');

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('evil.example/login', false)
        ->assertSee('<span>.</span>', false)
        ->assertDontSee('opacity-0">.</span>', false);
});

it('shows the record page through the sanitize() of a field that overrides it', function (): void {
    $field = new class('content') extends RichText
    {
        public function sanitize(?string $html): ?string
        {
            return $html === null ? null : str_replace('<b>', '', $html);
        }
    };

    expect((string) $field->display(new Article(['content' => '<p><b>Текст</b></p>'])))->toContain('<p>Текст</p>')->not->toContain('<b>');
});

it('shows the beginning of the text without tags in the list', function (): void {
    $field = RichText::make('content');
    $article = new Article(['content' => '<h2>Цены &amp; акции</h2><p>'.str_repeat('слово ', 40).'</p>']);

    expect($field->listDisplay($article))->toStartWith('Цены & акции слово слово')->toEndWith('...')
        ->and($field->listDisplay(new Article()))->toBeNull()
        ->and($field->height(100)->getHeight())->toBe(150);
});

it('does not show the text as a list column unless asked', function (): void {
    expect(RichText::make('content')->isVisibleInList())->toBeFalse()
        ->and(RichText::make('content')->showInList()->isVisibleInList())->toBeTrue();
});

it('can be used inside blocks', function (): void {
    $schema = HasMany::make('links')->fields([RichText::make('title')])->forModel(Article::class)->schema();

    expect($schema->field('title'))->toBeInstanceOf(RichText::class);
});

it('gives the editor the site styles from fastleo.rich_text', function (): void {
    config()->set('fastleo.rich_text.content_css', ['/css/site.css', 'https://cdn.example.com/site.css']);
    config()->set('fastleo.rich_text.content_style', 'img { max-height: 4rem; }');

    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertOk()
        ->assertSee('contentCss\u0022:[\u0022', false)
        ->assertSee('site.css\u0022,\u0022https:', false)
        ->assertSee('cdn.example.com', false)
        ->assertSee('contentStyle\u0022:\u0022img { max-height: 4rem; }\u0022', false);
});

it('gives the editor no site styles by default', function (): void {
    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertOk()
        ->assertSee('contentCss\u0022:[],\u0022contentStyle\u0022:null', false);
});

it('rejects an unsafe editor style setting', function (string $key, mixed $value): void {
    config()->set('fastleo.rich_text.'.$key, $value);

    expect(RichTextSettings::validate(...))->toThrow(LogicException::class, 'fastleo.rich_text.'.$key);
})->with([
    'protocol-relative address' => ['content_css', ['//evil.example.com/a.css']],
    'plain http address' => ['content_css', ['http://example.com/a.css']],
    'relative address' => ['content_css', ['css/a.css']],
    'address with a quote' => ['content_css', ['/a.css"onload=x']],
    'backslash address' => ['content_css', ['/\\evil.example.com/a.css']],
    'not a string address' => ['content_css', [123]],
    'closing tag in style' => ['content_style', 'a{}</style><script>x</script>'],
    'array as style' => ['content_style', ['a{}']],
]);

it('takes the template of a subclass from the site folder and falls back to the package one', function (): void {
    $dir = sys_get_temp_dir().'/fastleo-view-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($dir.'/fastleo/fields');
    View::getFinder()->prependLocation($dir);

    try {
        expect(new SiteRichText('content')->view())->toBe('fastleo::fields.rich-text');

        File::put($dir.'/fastleo/fields/site-rich-text.blade.php', 'site template');

        $field = new SiteRichText('content');

        expect($field->view())->toBe('fastleo.fields.site-rich-text')
            ->and(view($field->view())->render())->toBe('site template');

        // Шаблон сайта с именем поля пакета (своё поле `RichText`, сделанное make-field) поле пакета не подменяет.
        File::put($dir.'/fastleo/fields/rich-text.blade.php', 'site rich text');

        expect(RichText::make('content')->view())->toBe('fastleo::fields.rich-text')
            ->and((new class('content') extends RichText {})->view())->toBe('fastleo::fields.rich-text');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('rejects a text with thousands of nested tags instead of crashing the worker', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData(['content' => str_repeat('<b>', 30_000).'x']))
        ->assertSessionHasErrors(['content' => 'В тексте слишком сложная разметка: теги вложены глубже 200 уровней, их больше 30 000, в одном теге больше 1 000 атрибутов или незакрытое форматирование повторяется в каждом абзаце. Упростите разметку или разбейте текст на части.']);

    expect(Article::query()->count())->toBe(0);
});

it('rejects a text with a tag of thousands of attributes instead of parsing it for minutes', function (): void {
    $attributes = implode(' ', array_map(fn (int $number): string => 'a'.$number, range(1, 40_000)));

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['content' => '<p '.$attributes.'>x</p>']))
        ->assertSessionHasErrors(['content' => 'В тексте слишком сложная разметка: теги вложены глубже 200 уровней, их больше 30 000, в одном теге больше 1 000 атрибутов или незакрытое форматирование повторяется в каждом абзаце. Упростите разметку или разбейте текст на части.']);

    expect(Article::query()->count())->toBe(0);
});

it('does not open a stored text that is too complex for the cleaner as an empty editor that would erase it on the next save', function (): void {
    // Текст, записанный до пределов сложности (старый HTML, сидер, импорт прошлой версии).
    $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
    $article = Article::factory()->create(['title' => 'Было', 'content' => $stored]);

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('Текст слишком сложный для редактора', false)
        ->assertDontSee('name="content"', false);

    // Форма без поля не трогает текст: заголовок меняется, текст остаётся.
    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['title' => 'Стало']))->assertSessionHasNoErrors();

    expect(Article::query()->sole()->only('title', 'content'))->toBe(['title' => 'Стало', 'content' => $stored]);
});

it('opens the editor as usual for a stored text the cleaner takes and for a text the administrator has just sent', function (): void {
    $article = Article::factory()->create(['content' => '<p>Обычный текст</p>']);

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('name="content"', false)
        ->assertDontSee('Текст слишком сложный для редактора', false);

    // Слишком сложный текст, который пользователь прислал сам, не сохранён: форма остаётся с редактором и ошибкой.
    $this->followingRedirects()
        ->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['content' => str_repeat('<b>', 30_000).'x']))
        ->assertOk()
        ->assertSee('name="content"', false)
        ->assertSee('В тексте слишком сложная разметка', false)
        ->assertDontSee('Текст слишком сложный для редактора', false);
});

it('does not make a required rich text field unsavable when its stored text is too complex for the editor', function (): void {
    $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
    $article = Article::factory()->create(['title' => 'Было', 'content' => $stored]);
    $field = RichText::make('content')->required()->forModel(Article::class);

    expect($field->getRules($article)['content'][0])->toBe('sometimes')
        ->and($field->getRules(null)['content'][0])->toBe('required')
        ->and($field->getRules(Article::factory()->create(['content' => '<p>Текст</p>']))['content'][0])->toBe('required');
});

it('stops at the length limit and does not parse a text that is too long at all', function (): void {
    $started = hrtime(true);

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['content' => str_repeat('<div></x>', 200_000)]))
        ->assertSessionHasErrors('content');

    // Одна ошибка по полю — длина: правила разбора (вложенность, обязательность) после неё не запускались.
    expect(session('errors')->get('content'))->toHaveCount(1)
        ->and(session('errors')->first('content'))->toContain('1000000')
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0)
        ->and(Article::query()->count())->toBe(0);
});

describe('a stored text that is too complex for the editor, where the form cannot leave the field out', function (): void {
    beforeEach(function (): void {
        config(['fastleo.locales' => ['ru', 'en']]);
        useFixtureResources('RichTranslated');
    });

    it('shows the stored text of a language as html code instead of leaving the language out of the form, which would erase it', function (): void {
        $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
        $page = Page::factory()->create(['slug' => 'about', 'title' => ['ru' => $stored, 'en' => '<p>Text</p>']]);

        $this->get(route('fastleo.resources.edit', ['rich-pages', $page->id]))
            ->assertOk()
            ->assertSee('Показан как HTML-код', false)
            ->assertSee('name="title[ru]"', false)
            ->assertSee('name="title[en]"', false);
    });

    it('does not save a language that is too complex, so that saving another language cannot erase it', function (): void {
        $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
        $page = Page::factory()->create(['slug' => 'about', 'title' => ['ru' => $stored, 'en' => '<p>Text</p>']]);

        $this->put(route('fastleo.resources.update', ['rich-pages', $page->id]), ['title' => ['ru' => $stored, 'en' => '<p>New</p>'], 'slug' => 'about', 'faq' => ''])
            ->assertSessionHasErrors('title.ru');

        expect(Page::query()->findOrFail($page->id)->title)->toBeStoredJson(['ru' => $stored, 'en' => '<p>Text</p>']);
    });

    it('shows the stored text of a repeater item as html code too, and keeps it until the markup is simplified', function (): void {
        $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
        $page = Page::factory()->create(['slug' => 'about', 'faq' => [['question' => 'Как?', 'answer' => $stored]]]);

        $this->get(route('fastleo.resources.edit', ['rich-pages', $page->id]))
            ->assertOk()
            ->assertSee('Показан как HTML-код', false)
            ->assertSee('name="faq[0][answer]"', false);

        $this->put(route('fastleo.resources.update', ['rich-pages', $page->id]), ['title' => ['ru' => '<p>Заголовок</p>'], 'slug' => 'about', 'faq' => [['question' => 'Как?', 'answer' => $stored]]])
            ->assertSessionHasErrors('faq.0.answer');

        expect(Page::query()->findOrFail($page->id)->faq)->toBeStoredJson([['question' => 'Как?', 'answer' => $stored]]);

        $this->put(route('fastleo.resources.update', ['rich-pages', $page->id]), ['title' => ['ru' => '<p>Заголовок</p>'], 'slug' => 'about', 'faq' => [['question' => 'Как?', 'answer' => '<p>Коротко</p>']]])
            ->assertSessionHasNoErrors();

        expect(Page::query()->findOrFail($page->id)->faq)->toBeStoredJson([['question' => 'Как?', 'answer' => '<p>Коротко</p>']]);
    });

    it('goes on showing the stored text of an item as html code after the form was refused, so that saving again cannot erase it', function (): void {
        $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
        $page = Page::factory()->create(['slug' => 'about', 'faq' => [['question' => 'Как?', 'answer' => $stored]]]);
        $edit = route('fastleo.resources.edit', ['rich-pages', $page->id]);

        $this->from($edit)->put(route('fastleo.resources.update', ['rich-pages', $page->id]), ['title' => ['ru' => '<p>Заголовок</p>'], 'slug' => 'about', 'faq' => [['question' => 'Как?', 'answer' => $stored]]])
            ->assertSessionHasErrors('faq.0.answer');

        // После ошибки повторитель отдаёт подполям пустую запись: пустой редактор стёр бы текст следующим сохранением.
        $this->get($edit)
            ->assertOk()
            ->assertSee('Показан как HTML-код', false)
            ->assertSee('name="faq[0][answer]"', false)
            ->assertSee('&lt;p&gt;абзац&lt;/p&gt;', false);
    });

    it('lets a required field with such a stored text be left out of the form, but refuses to blank it', function (): void {
        $stored = str_repeat('<p>абзац</p>', DepthLimitedParser::MAX_TAGS + 1);
        $article = Article::factory()->create(['content' => $stored]);
        $field = RichText::make('content')->required()->forModel(Article::class);
        $rules = $field->getRules($article)['content'];

        // Поле можно не присылать (форма его не отправляет), но присланное пустое значение отклоняется.
        expect($rules[0])->toBe('sometimes')
            ->and($rules[1])->toBe('required')
            ->and(validator([], ['content' => $rules])->passes())->toBeTrue()
            ->and(validator(['content' => ''], ['content' => $rules])->passes())->toBeFalse()
            ->and(validator(['content' => null], ['content' => $rules])->passes())->toBeFalse();
    });

    it('opens the editor for a language and an item whose stored text the cleaner takes', function (): void {
        $page = Page::factory()->create(['slug' => 'about', 'title' => ['ru' => '<p>Обычный</p>'], 'faq' => [['question' => 'Как?', 'answer' => '<p>Ответ</p>']]]);

        $this->get(route('fastleo.resources.edit', ['rich-pages', $page->id]))
            ->assertOk()
            ->assertDontSee('Показан как HTML-код', false)
            ->assertSee('fastleoRichText(', false);
    });
});
