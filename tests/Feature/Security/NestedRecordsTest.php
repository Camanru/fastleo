<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\RecordWriter;
use Workbench\App\Models\Article;
use Workbench\App\Models\ArticleLink;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('does not change or take over a block of another record', function (): void {
    $victim = Article::factory()->create();
    $foreign = $victim->links()->create(['title' => 'Чужая', 'sort' => 1]);
    $article = Article::factory()->create();

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => [
        ['id' => (string) $foreign->id, 'title' => 'Взломано'],
    ]]))->assertSessionHasNoErrors();

    expect($foreign->fresh()?->title)->toBe('Чужая')
        ->and($foreign->fresh()?->article_id)->toBe($victim->id)
        ->and($article->links()->count())->toBe(0);
});

it('does not delete blocks of another record', function (): void {
    $victim = Article::factory()->create();
    $victim->links()->create(['title' => 'Чужая']);
    $article = Article::factory()->create();

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => '']));

    expect($victim->links()->count())->toBe(1);
});

it('writes only described block fields', function (): void {
    $other = Article::factory()->create();
    $article = Article::factory()->create();

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => [
        ['title' => 'Ссылка', 'article_id' => (string) $other->id, 'sort' => '99'],
    ]]))->assertSessionHasNoErrors();

    $link = ArticleLink::query()->sole();
    expect($link->article_id)->toBe($article->id)->and($link->sort)->toBe(1);
});

it('rejects a block that is not an array', function (): void {
    $article = Article::factory()->create();

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => ['строка', ['title' => 'Ссылка']]]))
        ->assertSessionHasErrors('links.0.title');

    expect($article->links()->count())->toBe(0);
});

it('cuts a list that is far over its limit before the rules are expanded per element', function (): void {
    $article = Article::factory()->create();
    $resource = Fastleo::resources()->find('articles');
    $blocks = array_fill(0, 5000, ['title' => 'Ссылка']);

    $input = RecordWriter::controllingInput($resource, $article, articleData(['links' => $blocks]));

    // Один лишний элемент остаётся: правило `max:10` откажет, а разворачивать 5000 элементов не нужно.
    expect($input['links'])->toHaveCount(11);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['links' => $blocks]))
        ->assertSessionHasErrors('links');

    // Форма после ошибки рисует блоки из старого ввода: в сессии остаётся обрезанный список, а не пять тысяч блоков.
    expect(session()->getOldInput('links'))->toHaveCount(11)
        ->and($article->links()->count())->toBe(0);
});
