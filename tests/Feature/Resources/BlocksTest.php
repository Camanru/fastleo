<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Files\GenerateImageVariants;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Данные формы страницы с блоками.
 *
 * @param  array<array-key, mixed>  $content
 * @return array<string, mixed>
 */
function pageWithBlocks(array $content): array
{
    return ['title' => ['ru' => 'О клинике'], 'slug' => 'about', 'faq' => '', 'specs' => '', 'content' => $content];
}

it('saves blocks in the order of the form with only the fields of their type', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageWithBlocks([
        'q' => ['type' => 'quote', 'quote' => ['text' => ' Лучшие врачи ', 'author' => ''], 'text' => ['body' => '<p>чужое</p>']],
        't' => ['type' => 'text', 'text' => ['body' => '<p>Привет</p>', 'secret' => 'x']],
    ]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->content)->toBeStoredJson([
        ['type' => 'quote', 'data' => ['text' => 'Лучшие врачи', 'author' => null]],
        ['type' => 'text', 'data' => ['body' => '<p>Привет</p>']],
    ]);
});

it('checks the fields of each block by its type', function (): void {
    $this->from(route('fastleo.resources.create', 'site-pages'))
        ->post(route('fastleo.resources.store', 'site-pages'), pageWithBlocks([
            ['type' => 'quote', 'quote' => ['text' => '']],
            ['type' => 'text'],
            ['type' => 'quote', 'quote' => ['text' => 'Есть']],
        ]))
        ->assertSessionHasErrors([
            'content.0.quote.text' => 'Заполните поле «Цитата».',
            'content.1.text.body' => 'Заполните поле «Текст».',
        ])
        ->assertSessionDoesntHaveErrors('content.2.quote.text');

    expect(Page::query()->count())->toBe(0);
});

it('rejects a block of an unknown type', function (string $type): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageWithBlocks([['type' => $type]]))
        ->assertSessionHasErrors('content.0.type');
})->with(['unknown' => 'video', 'path' => '../secret']);

it('cleans the html of a rich text inside a block', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageWithBlocks([
        ['type' => 'text', 'text' => ['body' => '<p onclick="alert(1)">Текст</p><script>alert(1)</script>']],
    ]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->content[0]['data']['body'] ?? null)->toBe('<p>Текст</p>');
});

it('queues variants for new pictures of image and gallery blocks', function (): void {
    Storage::fake('public');

    foreach (['a', 'b', 'c'] as $name) {
        Storage::disk('public')->put("uploads/pages/{$name}.jpg", 'x');
    }

    config(['fastleo.images.presets' => ['card' => ['widths' => [400], 'ratio' => null]]]);
    Queue::fake();

    $this->post(route('fastleo.resources.store', 'site-pages'), pageWithBlocks([
        ['type' => 'image', 'image' => ['src' => 'uploads/pages/a.jpg']],
        ['type' => 'gallery', 'gallery' => ['photos' => ['uploads/pages/b.jpg', 'uploads/pages/c.jpg']]],
    ]))->assertSessionHasNoErrors();

    Queue::assertPushed(GenerateImageVariants::class, 3);
});

it('shows the saved blocks in the form, after an error and on the record page', function (): void {
    $page = Page::factory()->create();

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->assertOk()
        ->assertSee('name="content[0][type]" value="text"', false)
        ->assertSee('name="content[1][quote][text]"', false)
        ->assertSee('data-block-type="gallery"', false);

    $this->from(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->put(route('fastleo.resources.update', ['site-pages', $page->id]), pageWithBlocks(['new-1' => ['type' => 'quote', 'quote' => ['text' => '', 'author' => 'Автор']]]) + ['slug' => $page->slug])
        ->assertSessionHasErrors('content.new-1.quote.text');

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->assertSee('name="content[new-1][quote][author]"', false)
        ->assertDontSee('name="content[0][type]"', false);

    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertSee('Лучшие врачи города.');
});

it('renders the blocks with the templates of the site and skips the rest', function (): void {
    $html = Blade::render('<x-fastleo::blocks :blocks="$blocks" />', ['blocks' => [
        ['type' => 'quote', 'data' => ['text' => 'Цитата <b>', 'author' => 'Автор']],
        ['type' => 'video', 'data' => ['url' => 'x']],
        ['type' => '../page', 'data' => []],
        'мусор',
        ['type' => 'text', 'data' => ['body' => '<p>Текст</p>', 'not a name' => 'x']],
    ]]);

    expect($html)->toContain('<p>Цитата &lt;b&gt;</p>')
        ->toContain('— Автор')
        ->toContain('<div class="block-text"><p>Текст</p></div>')
        ->not->toContain('video')
        ->and(Blade::render('<x-fastleo::blocks :blocks="null" />'))->toBe('');
});

it('shows the blocks on the page of the site', function (): void {
    $page = Page::factory()->create();

    $this->get(route('site-pages.show', $page->slug))->assertOk()->assertSee('Клиника работает с 2005 года.', false);
});

it('refuses a block type described twice or with a bad name', function (): void {
    expect(fn (): Blocks => Blocks::make('content')->blocks([Block::make('text'), Block::make('text')]))->toThrow(LogicException::class, 'дважды')
        ->and(fn (): Block => Block::make('Text'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Block => Block::make('type'))->toThrow(InvalidArgumentException::class);
});
