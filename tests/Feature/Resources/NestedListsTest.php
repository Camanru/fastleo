<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;
use Fastleo\Files\GenerateImageVariants;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    useFixtureResources('Nested');
});

/**
 * Данные формы страницы со списками внутри элементов.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nestedPage(array $overrides = []): array
{
    return ['title' => ['ru' => 'О клинике'], 'slug' => 'about', 'faq' => '', 'content' => '', ...$overrides];
}

it('saves a repeater inside a block with the rules of the outer list', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), nestedPage(['content' => [
        'a' => ['type' => 'plans', 'plans' => ['title' => 'Базовый', 'items' => [
            'x' => ['name' => ' Осмотр ', 'price' => '500', 'about' => '<p onclick="x()">Первичный</p>'],
            'y' => ['name' => 'Чистка', 'price' => '', 'about' => ''],
        ]]],
    ]]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->content)->toBeStoredJson([
        ['type' => 'plans', 'data' => ['title' => 'Базовый', 'items' => [
            ['name' => 'Осмотр', 'price' => '500', 'about' => '<p>Первичный</p>'],
            ['name' => 'Чистка', 'price' => null, 'about' => null],
        ]]],
    ]);
});

it('saves a repeater and blocks inside a repeater item in the order of the form', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), nestedPage(['faq' => [
        'b' => ['question' => 'Как записаться?', 'kind' => 'text', 'steps' => [
            'z' => ['title' => 'Позвонить', 'mode' => 'plain', 'extra' => 'не нужно'],
            'y' => ['title' => 'Прийти', 'mode' => 'extra', 'extra' => 'с паспортом'],
        ], 'extras' => [
            'n' => ['type' => 'note', 'note' => ['text' => 'Приходите заранее'], 'photo' => ['src' => 'чужое']],
        ]],
    ]]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->faq)->toBeStoredJson([
        ['question' => 'Как записаться?', 'kind' => 'text', 'steps' => [
            ['title' => 'Позвонить', 'mode' => 'plain'],
            ['title' => 'Прийти', 'mode' => 'extra', 'extra' => 'с паспортом'],
        ], 'extras' => [
            ['type' => 'note', 'data' => ['text' => 'Приходите заранее']],
        ]],
    ]);
});

it('keeps the order of the inner items when an early item misses an optional sub field', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), nestedPage(['faq' => [
        ['question' => 'Вопрос', 'steps' => [['title' => 'Первый'], ['mode' => 'plain', 'title' => 'Второй'], ['title' => 'Третий']]],
    ]]))->assertSessionHasNoErrors();

    expect(array_column(Page::query()->firstOrFail()->faq[0]['steps'] ?? [], 'title'))->toBe(['Первый', 'Второй', 'Третий']);
});

it('checks the sub fields of the inner lists with the full path', function (): void {
    $this->from(route('fastleo.resources.create', 'site-pages'))
        ->post(route('fastleo.resources.store', 'site-pages'), nestedPage([
            'faq' => [['question' => 'Вопрос', 'steps' => [
                ['title' => 'Ок', 'mode' => 'plain'],
                ['title' => '', 'mode' => 'plain'],
                ['title' => 'Особый', 'mode' => 'extra', 'extra' => ''],
            ], 'extras' => [['type' => 'note', 'note' => ['text' => '']]]]],
            'content' => [['type' => 'plans', 'plans' => ['title' => 'Тариф', 'items' => [['name' => '']]]]],
        ]))
        ->assertSessionHasErrors([
            'faq.0.steps.1.title' => 'Заполните поле «Title».',
            'faq.0.steps.2.extra' => 'Заполните поле «Extra».',
            'faq.0.extras.0.note.text' => 'Заполните поле «Text».',
            'content.0.plans.items.0.name' => 'Заполните поле «Name».',
        ])
        ->assertSessionDoesntHaveErrors(['faq.0.steps.0.title', 'faq.0.steps.0.extra']);

    expect(Page::query()->count())->toBe(0);
});

it('limits the number of the inner items', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), nestedPage(['faq' => [['question' => 'Вопрос', 'steps' => array_fill(0, 6, ['title' => 'Шаг'])]]]))
        ->assertSessionHasErrors('faq.0.steps');
});

it('refuses a third level of lists', function (): void {
    $inner = Repeater::make('steps')->fields([Repeater::make('parts')->fields([Text::make('title')])]);
    Repeater::make('faq')->fields([$inner])->schema();

    $inner->schema();
})->throws(LogicException::class, 'на один уровень');

it('refuses a third level of lists in blocks', function (): void {
    $inner = Blocks::make('inner')->blocks([Block::make('deep')->fields([Repeater::make('parts')->fields([Text::make('title')])])]);
    Blocks::make('content')->blocks([Block::make('plans')->fields([$inner])])->typeFields('plans');

    $inner->typeFields('deep');
})->throws(LogicException::class, 'на один уровень');

it('queues variants for new pictures of inner lists only', function (): void {
    Storage::fake('public');

    foreach (['a', 'b', 'c'] as $name) {
        Storage::disk('public')->put("uploads/pages/{$name}.jpg", 'x');
    }

    config(['fastleo.images.presets' => ['card' => ['widths' => [400], 'ratio' => null]]]);
    $page = Page::factory()->create(['faq' => [[
        'question' => 'Старый',
        'steps' => [['title' => 'Шаг', 'icon' => 'uploads/pages/a.jpg']],
        'extras' => [['type' => 'photo', 'data' => ['src' => 'uploads/pages/b.jpg']]],
    ]]]);
    Queue::fake();

    $this->put(route('fastleo.resources.update', ['site-pages', $page->id]), nestedPage(['slug' => $page->slug, 'faq' => [[
        'question' => 'Старый',
        'steps' => [['title' => 'Шаг', 'icon' => 'uploads/pages/a.jpg'], ['title' => 'Новый', 'icon' => 'uploads/pages/c.jpg']],
        'extras' => [['type' => 'photo', 'photo' => ['src' => 'uploads/pages/b.jpg']], ['type' => 'photo', 'photo' => ['src' => 'uploads/pages/a.jpg']]],
    ]]]))->assertSessionHasNoErrors();

    Queue::assertPushed(GenerateImageVariants::class, 2);
    Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/pages/c.jpg');
    Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/pages/a.jpg');
});

it('shows the inner lists in the form with their own key marks, after an error and on the record page', function (): void {
    $page = Page::factory()->create([
        'faq' => [['question' => 'Как записаться?', 'steps' => [['title' => 'Позвонить', 'mode' => 'plain'], ['title' => 'Прийти', 'mode' => 'plain']], 'extras' => [['type' => 'note', 'data' => ['text' => 'Заранее']]]]],
        'content' => [['type' => 'plans', 'data' => ['title' => 'Базовый', 'items' => [['name' => 'Осмотр', 'price' => 500]]]]],
    ]);

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->assertOk()
        ->assertSee('name="faq[0][steps][1][title]"', false)
        ->assertSee('name="faq[0][extras][0][note][text]"', false)
        ->assertSee('name="content[0][plans][items][0][name]"', false)
        ->assertSee('name="faq[__KEY__][steps][__KEY_1__][title]"', false)
        ->assertSee('name="content[__KEY__][plans][items][__KEY_1__][name]"', false);

    $this->from(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->put(route('fastleo.resources.update', ['site-pages', $page->id]), nestedPage(['slug' => $page->slug, 'faq' => ['new-1' => ['question' => 'Вопрос', 'steps' => ['new-2' => ['title' => '', 'mode' => 'plain']]]]]))
        ->assertSessionHasErrors('faq.new-1.steps.new-2.title');

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->assertOk()
        ->assertSee('name="faq[new-1][steps][new-2][title]"', false)
        ->assertSee('Заполните поле «Title».', false)
        ->assertDontSee('name="faq[0][question]"', false);

    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertSee(['Позвонить', 'Осмотр', 'Заранее']);
});
