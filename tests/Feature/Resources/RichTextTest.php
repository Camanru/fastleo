<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\RichText;
use Fastleo\Support\Assets;
use Fastleo\Support\Html\RichTextSettings;
use Fastleo\Tests\Fixtures\Fields\SiteRichText;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Workbench\App\Models\Article;

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

it('shows the beginning of the text without tags in the list', function (): void {
    $field = RichText::make('content');
    $article = new Article(['content' => '<h2>Цены &amp; акции</h2><p>'.str_repeat('слово ', 40).'</p>']);

    expect($field->listDisplay($article))->toStartWith('Цены & акции слово слово')->toEndWith('...')
        ->and($field->listDisplay(new Article))->toBeNull()
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
