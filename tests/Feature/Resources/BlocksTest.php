<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Email;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;
use Fastleo\Files\GenerateImageVariants;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
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
    return ['title' => ['ru' => 'О магазине'], 'slug' => 'about', 'faq' => '', 'specs' => '', 'content' => $content];
}

it('saves blocks in the order of the form with only the fields of their type', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageWithBlocks([
        'q' => ['type' => 'quote', 'quote' => ['text' => ' Лучшие цены ', 'author' => ''], 'text' => ['body' => '<p>чужое</p>']],
        't' => ['type' => 'text', 'text' => ['body' => '<p>Привет</p>', 'secret' => 'x']],
    ]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->content)->toBeStoredJson([
        ['type' => 'quote', 'data' => ['text' => 'Лучшие цены', 'author' => null]],
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

    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertSee('Лучшие цены города.');
});

it('shows the title of a block in the header of the block, next to its type', function (): void {
    $page = Page::factory()->create();

    $html = (string) $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))->assertOk()->getContent();

    expect($html)->toContain('data-block-hint')
        // У блока «Цитата» заголовка нет: подпись берётся из первого простого текстового поля («Автор»).
        ->toContain('content[1][quote][author]')
        ->toContain('x-init="hint = $el.querySelector(')
        ->toContain('x-on:input="if ($event.target.name ===');
});

it('takes the grey label of a block from the field title, then name, then the first plain text field, and never from a subclass of Text', function (): void {
    $field = Blocks::make('content', 'Содержимое')->blocks([
        Block::make('titled', 'С заголовком')->fields([Text::make('name', 'Имя'), Text::make('title', 'Заголовок')]),
        Block::make('named', 'С названием')->fields([Text::make('subtitle', 'Подзаголовок'), Text::make('name', 'Название')]),
        Block::make('plain', 'Простой')->fields([Email::make('mail', 'Почта'), Text::make('headline', 'Шапка')]),
        Block::make('none', 'Без подписи')->fields([Email::make('mail2', 'Почта'), RichText::make('body', 'Текст')]),
    ]);
    view()->share('errors', new ViewErrorBag());
    $header = fn (string $type): string => view('fastleo::fields.blocks-block', [
        'field' => $field, 'type' => $type, 'key' => '0', 'item' => null, 'path' => 'content', 'name' => 'content', 'id' => 'field-content', 'writable' => true, 'errors' => new ViewErrorBag(),
    ])->render();

    // Что подписывает блок, видно по x-init: он читает значение именно этого поля.
    $label = fn (string $type): string => preg_match('~x-init="([^"]*)"~', $header($type), $match) === 1 ? $match[1] : '';

    expect($label('titled'))->toContain('content[0][titled][title]')->not->toContain('[titled][name]')
        ->and($label('named'))->toContain('content[0][named][name]')->not->toContain('[named][subtitle]')
        ->and($label('plain'))->toContain('content[0][plain][headline]')->not->toContain('[plain][mail]')
        ->and($label('none'))->toBe('')
        // «+» в шапке называет свой блок: одинаковых кнопок для чтения с экрана не бывает.
        ->and($header('titled'))->toContain('x-bind:aria-label=');
});

it('puts a button into the header of every block to add a new block right after it, but not on the record page', function (): void {
    $page = Page::factory()->create();

    $html = (string) $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))->assertOk()->getContent();

    // Два сохранённых блока и по одному шаблону на каждый тип в меню: у каждого есть своя кнопка «+».
    expect(substr_count($html, 'data-block-insert'))->toBeGreaterThanOrEqual(count($page->content) + 1)
        ->and($html)->toContain('openMenu($el, $el.closest(\'[data-block]\'))')
        ->and($html)->toContain('data-block-add');

    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertDontSee('data-block-insert', false);
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

it('does not let a field of a block replace the variables of Blade', function (): void {
    // Шаблон с `@foreach` пользуется `$__env`: поле с таким именем сломало бы его.
    $html = Blade::render('<x-fastleo::blocks :blocks="$blocks" />', ['blocks' => [
        ['type' => 'gallery', 'data' => ['__env' => 'x', 'errors' => 'y', 'obLevel' => 'z', 'photos' => ['uploads/blocks/one.jpg']]],
        ['type' => 'text', 'data' => ['body' => '<p>Живой текст</p>']],
    ]]);

    expect($html)->toContain('uploads/blocks/one.jpg')
        ->toContain('<div class="block-text"><p>Живой текст</p></div>');
});

it('shows the blocks on the page of the site', function (): void {
    $page = Page::factory()->create();

    $this->get(route('site-pages.show', $page->slug))->assertOk()->assertSee('Магазин работает с 2005 года.', false);
});

it('refuses a block type described twice or with a bad name', function (): void {
    expect(fn (): Blocks => Blocks::make('content')->blocks([Block::make('text'), Block::make('text')]))->toThrow(LogicException::class, 'дважды')
        ->and(fn (): Block => Block::make('Text'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Block => Block::make('type'))->toThrow(InvalidArgumentException::class);
});
