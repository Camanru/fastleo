<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\RichText;
use Fastleo\Support\Assets;
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

it('can be used inside blocks', function (): void {
    $schema = HasMany::make('links')->fields([RichText::make('title')])->forModel(Article::class)->schema();

    expect($schema->field('title'))->toBeInstanceOf(RichText::class);
});
