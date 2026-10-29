<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    config(['app.timezone' => 'UTC', 'fastleo.timezone' => 'Europe/Moscow']);
});

/**
 * Заполненная форма статьи.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function articlePayload(array $overrides = []): array
{
    return [
        'type' => 'news',
        'title' => 'Открытие клиники',
        'excerpt' => "Первая строка\nвторая строка",
        'status' => 'review',
        'views' => '15',
        'price' => '1990.50',
        'tags' => ['news', 'sale'],
        'author_email' => 'author@example.com',
        'phone' => '+7 900 000-00-00',
        'source_url' => 'https://example.com/news',
        'color' => '#1e90ff',
        'is_published' => '1',
        'is_featured' => '1',
        'published_on' => '2026-03-08',
        'starts_at' => '2026-03-08T12:30',
        'opens_at' => '09:00',
        ...$overrides,
    ];
}

it('renders every field type and the layout', function (): void {
    $response = $this->get(route('fastleo.resources.create', 'articles'));

    $response->assertOk()
        ->assertSee('role="tablist"', false)
        ->assertSeeInOrder(['Основное', 'Контакты'])
        ->assertSee('Автор')
        ->assertSee('Не показывается на сайте')
        ->assertSee('md:grid-cols-3', false)
        ->assertSee('<input type="hidden" name="type" value="news"', false)
        ->assertSee('type="number"', false)
        ->assertSee('type="email"', false)
        ->assertSee('type="tel"', false)
        ->assertSee('type="url"', false)
        ->assertSee('type="date"', false)
        ->assertSee('type="datetime-local"', false)
        ->assertSee('type="time"', false)
        ->assertSee('type="color"', false)
        ->assertSee('role="switch"', false)
        ->assertSee('name="tags[]"', false)
        ->assertSee('<option value="review"', false)
        ->assertSee('На проверке')
        ->assertSee('value="0"', false);
});

it('saves every field type through the model', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articlePayload())->assertSessionHasNoErrors();

    $article = Article::query()->sole();
    expect($article->status)->toBe(ArticleStatus::Review)
        ->and($article->tags)->toBe(['news', 'sale'])
        ->and($article->views)->toBe(15)
        ->and($article->price)->toBe('1990.50')
        ->and($article->is_published)->toBeTrue()
        ->and($article->is_featured)->toBeTrue()
        ->and($article->published_on?->toDateString())->toBe('2026-03-08')
        ->and($article->getRawOriginal('starts_at'))->toBe('2026-03-08 09:30:00')
        ->and($article->opens_at)->toStartWith('09:00') // MySQL и PostgreSQL отдают 09:00:00
        ->and($article->excerpt)->toBe("Первая строка\nвторая строка")
        ->and($article->only('type', 'author_email', 'phone', 'source_url', 'color'))->toBe([
            'type' => 'news',
            'author_email' => 'author@example.com',
            'phone' => '+7 900 000-00-00',
            'source_url' => 'https://example.com/news',
            'color' => '#1e90ff',
        ]);
});

it('shows saved values in the edit form in the admin time zone', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articlePayload());
    $article = Article::query()->sole();

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('value="2026-03-08T12:30"', false)
        ->assertSee('value="09:00"', false)
        ->assertSee('<option value="sale" selected', false)
        ->assertSee('<option value="review" selected', false);
});

it('clears a multiple select and unchecks a toggle', function (): void {
    $article = Article::factory()->published()->create(['tags' => ['news']]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articlePayload(['tags' => '', 'is_published' => '0']))
        ->assertSessionHasNoErrors();

    expect($article->refresh()->tags)->toBe([])
        ->and($article->is_published)->toBeFalse();
});

it('rejects invalid values of each type with a Russian message', function (string $field, mixed $value, string $message): void {
    $response = $this->post(route('fastleo.resources.store', 'articles'), articlePayload([$field => $value]));

    $response->assertSessionHasErrors([$field => $message]);
    expect(Article::query()->count())->toBe(0);
})->with([
    'status outside the options' => ['status', 'deleted', 'Выбрано недопустимое значение поля «Статус».'],
    'negative views' => ['views', '-1', 'Поле «Просмотры» должно быть не меньше 0.'],
    'fractional views' => ['views', '1.5', 'Поле «Просмотры» должно быть целым числом.'],
    'price as text' => ['price', 'дорого', 'Поле «Цена, ₽» должно быть числом.'],
    'email' => ['author_email', 'не почта', 'Поле «Email автора» должно быть адресом электронной почты.'],
    'javascript link' => ['source_url', 'javascript:alert(1)', 'Поле «Источник» должно быть адресом (https://...).'],
    'color' => ['color', 'red', 'Поле «Цвет карточки» должно быть цветом в формате HEX (#1e90ff).'],
    'date format' => ['published_on', '08.03.2026', 'Поле «Дата публикации» должно быть в формате Y-m-d.'],
    'time format' => ['opens_at', '9 утра', 'Поле «Время открытия» должно быть в формате H:i.'],
    'toggle' => ['is_published', 'yes', 'Поле «Опубликовано» должно быть «да» или «нет».'],
    'text too long' => ['excerpt', str_repeat('а', 1001), 'Длина поля «Анонс» должна быть не больше 1000 символов.'],
]);

it('rejects a tag outside the options', function (): void {
    $response = $this->post(route('fastleo.resources.store', 'articles'), articlePayload(['tags' => ['news', 'hack']]));

    $response->assertSessionHasErrors(['tags.1' => 'Выбрано недопустимое значение поля «Метки».']);
});

it('shows the form again when a forged request sent arrays instead of the values of simple fields', function (): void {
    $arrays = collect(['title', 'slug', 'excerpt', 'views', 'price', 'status', 'author_email', 'phone', 'source_url', 'color', 'layout', 'rating', 'published_on', 'starts_at', 'opens_at', 'body', 'head_code', 'type', 'category_id'])
        ->mapWithKeys(fn (string $name): array => [$name => ['x', ['y']]])
        ->all();

    $this->followingRedirects()
        ->post(route('fastleo.resources.store', 'articles'), articlePayload($arrays))
        ->assertOk()
        ->assertSee('data-field="title"', false);
});

it('opens the tab that has an error', function (): void {
    $response = $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['author_email' => 'Ошибка']))])
        ->get(route('fastleo.resources.create', 'articles'));

    $response->assertOk()->assertSee('x-data="{ tab: 4 }"', false);
});

it('shows list values formatted by field type', function (): void {
    Article::factory()->published()->create([
        'title' => 'Статья',
        'status' => ArticleStatus::Published,
        'published_on' => '2026-03-08',
        'views' => 42,
    ]);

    $this->get(route('fastleo.resources.index', 'articles'))
        ->assertOk()
        ->assertSee('Опубликовано')
        ->assertSee('08.03.2026')
        ->assertSee('42')
        ->assertDontSee('Анонс');
});

it('shows the whole long text on the record page but a short one in the list', function (): void {
    $article = Article::factory()->create(['excerpt' => str_repeat('слово ', 30).'КОНЕЦ', 'type' => 'секрет']);

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))->assertOk()->assertSee('КОНЕЦ')->assertDontSee('секрет');
});
