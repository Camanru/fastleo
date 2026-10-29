<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('Actions');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Запустить действие раздела «articles» над записями.
 *
 * @param  list<mixed>|mixed  $ids
 * @param  array<string, mixed>  $data
 */
function runAction(string $action, mixed $ids, array $data = []): TestResponse
{
    return test()->from(route('fastleo.resources.index', 'articles'))
        ->post(route('fastleo.resources.action', ['articles', $action]), ['ids' => $ids, ...$data]);
}

it('runs an action on the selected records and reports the result', function (): void {
    [$first, $second, $other] = Article::factory()->count(3)->create(['is_featured' => false]);

    runAction('mark-featured', [(string) $first->id, (string) $second->id])
        ->assertRedirect(route('fastleo.resources.index', 'articles'))
        ->assertSessionHas('fastleo.success', 'На главной: 2');

    expect($first->fresh()?->is_featured)->toBeTrue()
        ->and($second->fresh()?->is_featured)->toBeTrue()
        ->and($other->fresh()?->is_featured)->toBeFalse();
});

it('takes integer keys as the tests of a site send them', function (): void {
    [$first, $second] = Article::factory()->count(2)->create(['is_featured' => false]);

    runAction('mark-featured', [$first->id, $second->id])->assertSessionHas('fastleo.success', 'На главной: 2');

    expect($first->fresh()?->is_featured)->toBeTrue();
});

it('leaves out records hidden by the query of the resource', function (): void {
    $news = Article::factory()->create(['is_featured' => false]);
    $page = Article::factory()->create(['type' => 'page', 'is_featured' => false]);

    runAction('mark-featured', [(string) $news->id, (string) $page->id])->assertSessionHas('fastleo.success', 'На главной: 1');
    runAction('mark-featured', [(string) $page->id])->assertNotFound();

    expect($page->fresh()?->is_featured)->toBeFalse();
});

it('skips records the policy does not allow and refuses when none is allowed', function (): void {
    $draft = Article::factory()->create();
    $published = Article::factory()->published()->create();

    runAction('delete', [(string) $draft->id, (string) $published->id])
        ->assertSessionHas('fastleo.success', 'Удалено: 1. Пропущено без прав: 1.');

    expect($draft->fresh()?->trashed())->toBeTrue()
        ->and($published->fresh()?->trashed())->toBeFalse();

    runAction('delete', [(string) $published->id])->assertForbidden();
});

it('checks its own policy ability and runs single-record actions on one record only', function (): void {
    [$first, $second] = Article::factory()->count(2)->create();

    runAction('archive-article', [(string) $first->id, (string) $second->id])->assertNotFound();
    // У ArticlePolicy нет метода archive — Gate запрещает.
    runAction('archive-article', [(string) $first->id])->assertForbidden();
});

it('asks for the mini form again with the errors when it is not valid', function (): void {
    $article = Article::factory()->create(['title' => 'Отчёт']);

    runAction('rename-articles', [(string) $article->id], ['data' => ['suffix' => '']])
        ->assertRedirect(route('fastleo.resources.index', 'articles'))
        ->assertSessionHasErrors(['data.suffix' => 'Заполните поле «Окончание».'])
        ->assertSessionHas('fastleo.action', 'rename-articles');

    expect($article->fresh()?->title)->toBe('Отчёт');
});

it('does not put a password-like value of the mini form into the session after an error', function (): void {
    $article = Article::factory()->create();

    runAction('rename-articles', [(string) $article->id], ['data' => ['suffix' => '', 'password' => 'top-secret', 'current_password' => 'top-secret']])
        ->assertSessionHasErrors(['data.suffix'])
        ->assertSessionMissing('_old_input.data.password')
        ->assertSessionMissing('_old_input.data.current_password');
});

it('passes the checked mini form to the action and follows its redirect', function (): void {
    $article = Article::factory()->create(['title' => 'Отчёт']);

    runAction('rename-articles', [(string) $article->id], ['data' => ['suffix' => '  за март ', 'title' => 'Взлом']])
        ->assertRedirect(route('fastleo.resources.index', 'articles'))
        ->assertSessionHas('fastleo.success', 'Переименовано');

    expect($article->fresh()?->title)->toBe('Отчёт за март');
});

it('returns the response of an action as it is', function (): void {
    $first = Article::factory()->create(['title' => 'Б']);
    $second = Article::factory()->create(['title' => 'А']);

    $response = runAction('export-titles', [(string) $first->id, (string) $second->id])->assertOk();

    expect($response->streamedContent())->toBe("А\nБ")
        ->and($response->headers->get('content-disposition'))->toContain('titles.txt');
});

it('shows an error result in red', function (): void {
    $article = Article::factory()->create();
    Gate::define('archive', fn (): bool => true);

    runAction('archive-article', [(string) $article->id])->assertSessionHas('fastleo.error', 'Архив недоступен');
});

it('explains a database error of an action', function (): void {
    $article = Article::factory()->create();

    // Ключ — из имени класса без «Action»: BrokenAction → broken.
    runAction('broken', [(string) $article->id])->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'уже'));
});

it('rejects a malformed selection with a message', function (mixed $ids): void {
    Article::factory()->create();

    runAction('mark-featured', $ids)->assertRedirect()->assertSessionHas('fastleo.error', 'Выберите записи (не больше 1000).');
})->with([
    'nothing' => [[]],
    'not a list' => 'all',
    'nested arrays' => [[['1']]],
    'not a key' => [['1 or 1=1']],
    'too many' => fn (): array => array_map(strval(...), range(1, 1001)),
]);

it('answers 404 for an unknown action', function (): void {
    $article = Article::factory()->create();

    runAction('unknown', [(string) $article->id])->assertNotFound();
});

it('offers bulk actions, the row menu and the buttons of a record only where they are allowed', function (): void {
    $draft = Article::factory()->create();
    Article::factory()->published()->create();

    $this->get(route('fastleo.resources.index', 'articles'))
        ->assertOk()
        ->assertSee('data-bulk-bar', false)
        ->assertSee('id="fastleo-action-form-delete"', false)
        ->assertSee('id="fastleo-action-rename-articles"', false)
        ->assertSee('На главную')
        ->assertDontSee('Archive Article');

    $this->get(route('fastleo.resources.show', ['articles', $draft->id]))->assertOk()->assertSee('Export Titles')->assertDontSee('Удалить навсегда');
    $this->get(route('fastleo.resources.edit', ['articles', $draft->id]))->assertOk()->assertSee('Дописать к заголовку');
});
