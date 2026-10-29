<?php

declare(strict_types=1);

use Fastleo\Exports\Exporter;
use Fastleo\Fastleo;
use Fastleo\Fields\Email;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Tests\Fixtures\Models\SpatiePage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    config(['fastleo.locales' => ['ru', 'en']]);
    useFixtureResources('Translations');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('saves the text of every language and drops empty and unknown ones', function (): void {
    $this->post(route('fastleo.resources.store', 'site-pages'), ['title' => ['ru' => ' О клинике ', 'en' => '', 'de' => 'Über uns'], 'slug' => ''])
        ->assertSessionHasNoErrors();

    expect(Page::query()->firstOrFail()->only('title', 'slug'))->toBe(['title' => ['ru' => 'О клинике'], 'slug' => 'o-klinike']);
});

it('requires only the main language and names the language in the errors', function (): void {
    $this->from(route('fastleo.resources.create', 'site-pages'))
        ->post(route('fastleo.resources.store', 'site-pages'), ['title' => ['ru' => '', 'en' => str_repeat('a', 21)]])
        ->assertSessionHasErrors([
            'title.ru' => 'Заполните поле «Заголовок (RU)».',
            'title.en' => 'Длина поля «Заголовок (EN)» должна быть не больше 20 символов.',
        ]);

    $this->post(route('fastleo.resources.store', 'site-pages'), ['title' => 'строка вместо языков'])->assertSessionHasErrors('title');
});

it('shows a tab for every language and opens the one with an error', function (): void {
    $page = Page::factory()->create(['title' => ['ru' => 'Главная', 'en' => 'Home']]);

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->assertOk()
        ->assertSee('data-locale="en"', false)
        ->assertSee('name="title[en]"', false)
        ->assertSee('value="Home"', false);

    $this->from(route('fastleo.resources.edit', ['site-pages', $page->id]))
        ->put(route('fastleo.resources.update', ['site-pages', $page->id]), ['title' => ['ru' => 'Главная', 'en' => str_repeat('a', 21)], 'slug' => $page->slug]);

    $this->get(route('fastleo.resources.edit', ['site-pages', $page->id]))->assertSee("x-data=\"{ locale: 'en' }\"", false);
});

it('shows the main language in the list and on the record page', function (): void {
    $page = Page::factory()->create(['title' => ['ru' => 'Главная', 'en' => 'Home']]);
    Page::factory()->create(['title' => ['en' => 'Only English']]);

    $this->get(route('fastleo.resources.index', 'site-pages'))->assertOk()->assertSee('Главная')->assertSee('Only English')->assertDontSee('Home');
    $this->get(route('fastleo.resources.show', ['site-pages', $page->id]))->assertOk()->assertSee('Главная')->assertDontSee('{&quot;ru&quot;', false);
});

it('finds records by any language and sorts by the main one', function (): void {
    Page::factory()->create(['title' => ['ru' => 'Бета', 'en' => 'Clinic']]);
    Page::factory()->create(['title' => ['ru' => 'Альфа', 'en' => 'Hospital']]);

    $titles = fn (array $query): array => array_map(
        fn (Page $page): string => $page->title['ru'],
        $this->get(route('fastleo.resources.index', ['resource' => 'site-pages', ...$query]))->viewData('records')->all(),
    );

    expect($titles(['q' => 'clinic']))->toBe(['Бета'])
        ->and($titles(['sort' => 'title']))->toBe(['Альфа', 'Бета']);
});

it('works with models of spatie/laravel-translatable', function (): void {
    $this->post(route('fastleo.resources.store', 'spatie-pages'), ['title' => ['ru' => 'Услуги', 'en' => 'Services'], 'slug' => ''])->assertSessionHasNoErrors();

    $page = SpatiePage::query()->firstOrFail();

    expect($page->getTranslations('title'))->toBeStoredJson(['ru' => 'Услуги', 'en' => 'Services'])
        ->and($page->getAttribute('slug'))->toBe('uslugi');

    $this->put(route('fastleo.resources.update', ['spatie-pages', $page->id]), ['title' => ['ru' => 'Услуги'], 'slug' => 'uslugi'])->assertSessionHasNoErrors();

    expect($page->fresh()?->getTranslations('title'))->toBe(['ru' => 'Услуги']);
    $this->get(route('fastleo.resources.edit', ['spatie-pages', $page->id]))->assertSee('value="Услуги"', false);
});

it('exports and imports a column for every language', function (): void {
    Storage::fake('local');
    $page = Page::factory()->create(['title' => ['ru' => 'Главная', 'en' => 'Home']]);

    $headers = new Exporter(Fastleo::resources()->find('site-pages') ?? throw new RuntimeException)->headers();
    expect($headers)->toBe(['id', 'Заголовок (RU)', 'Заголовок (EN)', 'Адрес']);

    $export = $this->get(route('fastleo.resources.export', ['resource' => 'site-pages', 'format' => 'csv']));
    expect(exportedRows($export, 'csv')[1])->toBe([(string) $page->id, 'Главная', 'Home', $page->slug]);

    $this->post(route('fastleo.resources.import', 'site-pages'), [
        'file' => UploadedFile::fake()->createWithContent('pages.csv', $export->streamedContent()),
    ]);
    expect($page->fresh()?->only('title', 'slug'))->toBeStoredJson(['title' => ['ru' => 'Главная', 'en' => 'Home'], 'slug' => $page->slug]);

    $this->post(route('fastleo.resources.import', 'site-pages'), [
        'file' => UploadedFile::fake()->createWithContent('pages.csv', "id;Заголовок (EN)\n{$page->id};Main page\n;Новая\n"),
    ]);

    expect($page->fresh()?->title)->toBeStoredJson(['ru' => 'Главная', 'en' => 'Main page'])
        ->and(Page::query()->whereKeyNot($page->id)->first())->toBeNull();

    $this->post(route('fastleo.resources.import', 'site-pages'), [
        'file' => UploadedFile::fake()->createWithContent('pages.csv', "Заголовок;title.en\nНовая;New\n"),
    ]);

    expect(Page::query()->whereKeyNot($page->id)->firstOrFail()->title)->toBeStoredJson(['ru' => 'Новая', 'en' => 'New']);
});

it('is available only for text fields', function (): void {
    expect(fn (): Email => Email::make('email')->translatable())->toThrow(LogicException::class, 'не может быть многоязычным')
        ->and(fn (): Select => Select::make('status')->translatable())->toThrow(LogicException::class);
});

it('needs the array cast of the column without spatie', function (): void {
    Text::make('excerpt')->translatable()->fill(new Article, ['ru' => 'Текст']);
})->throws(LogicException::class, 'хранит массив');

it('takes the languages from the config and falls back to the language of the site', function (): void {
    config(['fastleo.locales' => ['ru', 'en', 'ru', '../x', 5]]);
    expect(Fastleo::locales())->toBe(['ru', 'en']);

    config(['fastleo.locales' => null, 'app.locale' => 'kk']);
    expect(Fastleo::locales())->toBe(['kk']);
});
