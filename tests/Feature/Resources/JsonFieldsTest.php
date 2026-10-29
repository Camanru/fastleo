<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Files\GenerateImageVariants;
use Fastleo\Support\JsonItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Данные формы страницы.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pageData(array $overrides = []): array
{
    return ['title' => ['ru' => 'О клинике'], 'slug' => 'about', 'faq' => '', 'specs' => '', ...$overrides];
}

it('saves repeater items in the order of the form with only the described sub-fields', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageData(['faq' => [
        'b' => ['question' => ' Второй ', 'answer' => '', 'open' => '0', 'secret' => 'x'],
        'a' => ['question' => 'Первый', 'answer' => 'Ответ', 'open' => '1'],
    ]]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->faq)->toBeStoredJson([
        ['question' => 'Второй', 'answer' => null, 'open' => false],
        ['question' => 'Первый', 'answer' => 'Ответ', 'open' => true],
    ]);
});

it('checks the sub-fields of every item with readable messages', function (): void {
    $this->from(route('fastleo.resources.create', 'site-pages'))
        ->post(route('fastleo.resources.store', 'site-pages'), pageData(['faq' => [['question' => '', 'answer' => 'Без вопроса']]]))
        ->assertSessionHasErrors(['faq.0.question' => 'Заполните поле «Вопрос».']);

    expect(Page::query()->count())->toBe(0);
});

it('limits the number of items', function (): void {
    $items = array_fill(0, 21, ['question' => 'Вопрос']);

    $this->post(route('fastleo.resources.store', 'site-pages'), pageData(['faq' => $items]))->assertSessionHasErrors('faq');
});

it('clears the repeater when every item is removed', function (): void {
    $page = Page::factory()->create();

    $this->put(route('fastleo.resources.update', ['site-pages', $page->id]), pageData(['slug' => $page->slug]))->assertSessionHasNoErrors();

    expect($page->fresh()?->faq)->toBe([]);
});

it('shows the saved items in the form and on the record page', function (): void {
    $page = Page::factory()->create(['faq' => [['question' => 'Как записаться?', 'answer' => 'По телефону', 'open' => true]]]);

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->assertOk()
        ->assertSee('name="faq[0][question]"', false)
        ->assertSee('value="Как записаться?"', false);

    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertSee('По телефону');
});

it('queues variants for new pictures of the items only', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('uploads/pages/a.jpg', 'x');
    Storage::disk('public')->put('uploads/pages/b.jpg', 'x');
    config(['fastleo.images.presets' => ['card' => ['widths' => [400], 'ratio' => null]]]);
    useFixtureResources('Json');
    $page = Page::factory()->create(['faq' => [['question' => 'Старый', 'icon' => 'uploads/pages/a.jpg']]]);
    Queue::fake();

    $this->put(route('fastleo.resources.update', ['site-pages', $page->id]), pageData(['slug' => $page->slug, 'faq' => [
        ['question' => 'Старый', 'icon' => 'uploads/pages/a.jpg'],
        ['question' => 'Новый', 'icon' => 'uploads/pages/b.jpg'],
    ]]))->assertSessionHasNoErrors();

    Queue::assertPushed(GenerateImageVariants::class, 1);
    Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/pages/b.jpg');
});

it('saves key value pairs in the order of the form', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageData(['specs' => [
        ['key' => ' Вес ', 'value' => '1,5 кг'],
        ['key' => 'Цвет', 'value' => ''],
        ['key' => '', 'value' => ''],
    ]]))->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->specs)->toBe(['Вес' => '1,5 кг', 'Цвет' => null]);
});

it('rejects repeated or missing names of pairs', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageData(['specs' => [
        ['key' => 'Вес', 'value' => '1'],
        ['key' => 'Вес', 'value' => '2'],
        ['key' => '', 'value' => 'без названия'],
    ]]))->assertSessionHasErrors(['specs.0.key', 'specs.1.key', 'specs.2.key']);
});

it('treats names that differ only in letter case as one name', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageData(['specs' => [
        ['key' => 'Вес', 'value' => '1'],
        ['key' => 'вЕС', 'value' => '2'],
        ['key' => 'Цвет', 'value' => '3'],
    ]]))->assertSessionHasErrors(['specs.0.key' => 'В поле «Характеристики: параметр» есть повторяющееся значение.', 'specs.1.key']);

    expect(Page::query()->count())->toBe(0);
});

it('saves numeric names as an object, not as a list', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), pageData(['specs' => [
        ['key' => '0', 'value' => 'ноль'],
        ['key' => '1', 'value' => 'один'],
    ]]))->assertSessionHasNoErrors();

    expect(json_decode((string) DB::table('pages')->value('specs')))->toEqual((object) ['0' => 'ноль', '1' => 'один']);
});

it('shows the pairs in the form and on the record page', function (): void {
    $page = Page::factory()->create(['specs' => ['Площадь' => '120 м²']]);

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))->assertOk()->assertSee('value="Площадь"', false);
    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertSee('120 м²');
});

it('needs the array cast of the column', function (string $field): void {
    $record = new Article;
    $instance = $field === 'repeater' ? Repeater::make('excerpt')->fields([Text::make('a')]) : KeyValue::make('excerpt');

    $instance->fill($record, []);
})->with(['repeater', 'key value'])->throws(LogicException::class, 'хранит массив');

it('refuses relations, nested blocks and translatable fields inside a repeater', function (Field $inner): void {
    Repeater::make('faq')->fields([$inner])->schema();
})->throws(LogicException::class, 'нельзя использовать внутри блоков')->with([
    'relation' => fn (): HasMany => HasMany::make('links'),
    'translatable' => fn (): Text => Text::make('question')->translatable(),
]);

it('refuses sub fields whose stored value the form would erase', function (Field $inner): void {
    Repeater::make('faq')->fields([$inner])->schema();
})->throws(LogicException::class, 'не может быть readonly(), disabled(), hidden()')->with([
    'read only' => fn (): Text => Text::make('external_id')->readonly(),
    'disabled' => fn (): Text => Text::make('external_id')->disabled(),
    'hidden' => fn (): Text => Text::make('external_id')->hidden(),
    'hidden per record' => fn (): Text => Text::make('external_id')->hideOnEdit(),
    'read only for nobody' => fn (): Text => Text::make('external_id')->readonly(false),
    'hidden for nobody' => fn (): Text => Text::make('external_id')->hidden(false),
    'disabled for nobody' => fn (): Text => Text::make('external_id')->disabled(false),
    'hide on create for nobody' => fn (): Text => Text::make('external_id')->hideOnCreate(false),
]);

it('needs the field that a sub field depends on to be in the same repeater and open to every admin', function (array $fields): void {
    Repeater::make('faq')->fields($fields)->schema();
})->throws(LogicException::class, 'visibleWhen(\'kind\')')->with([
    'outside the repeater' => fn (): array => [Text::make('answer')->visibleWhen('kind', 'text')],
]);

it('accepts a sub field that depends on another field of the same repeater', function (): void {
    $schema = Repeater::make('faq')->fields([Select::make('kind')->options(['text' => 'Текст']), Text::make('answer')->visibleWhen('kind', 'text')])->schema();

    expect($schema->fields())->toHaveCount(2);
});

it('reads a sub field named like a method of the model', function (): void {
    $page = Page::factory()->create(['faq' => [['question' => 'Когда?']]]);
    $field = Repeater::make('faq')->fields([Text::make('question'), Text::make('from')]);

    expect(JsonItem::from(['question' => 'Когда?'])->getAttribute('from'))->toBeNull()
        ->and($field->display($page))->not->toBeNull();
});
