<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Slug;
use Fastleo\Support\RecordWriter;
use Fastleo\Tests\Fixtures\Models\SpatiePage;
use Illuminate\Contracts\Validation\Validator;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('SlugSource');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Проверка формы так, как её делает контроллер: правила записываемых полей над присланным вводом.
 *
 * @param  array<string, mixed>  $input
 */
function validateArticleForm(Article $article, array $input): Validator
{
    $resource = Fastleo::resources()->find('articles');
    $fields = $resource->writableFields($article);

    return validator(RecordWriter::controllingInput($resource, $article, $input), RecordWriter::rules($resource, $article, $fields));
}

it('does not take a title the administrator cannot change for the source of an automatic address', function (): void {
    Article::factory()->create(['title' => 'О компании', 'slug' => 'o-kompanii']);
    $article = Article::factory()->create(['title' => 'Контакты', 'slug' => 'kontakty']);

    // Присланный `title` не записывается (поле только для чтения): адрес чужой записи не должен считаться автоматическим.
    $validator = validateArticleForm($article, ['title' => 'О компании', 'slug' => 'o-kompanii']);

    expect($validator->errors()->keys())->toBe(['slug']);
});

it('still accepts the address built from the saved title when the title cannot be changed', function (): void {
    $article = Article::factory()->create(['title' => 'Контакты', 'slug' => 'kontakty']);

    expect(validateArticleForm($article, ['title' => 'Что угодно', 'slug' => 'kontakty'])->errors()->keys())->toBe([]);
});

it('reads the source of the address as all translations when the model keeps them, and as a plain value otherwise', function (): void {
    $spatie = new SpatiePage;
    $spatie->replaceTranslations('title', ['ru' => 'Услуги', 'en' => 'Services']);

    expect(Slug::make('slug')->from('title')->sourceOf($spatie))->toBe(['ru' => 'Услуги', 'en' => 'Services'])
        ->and(Slug::make('slug')->from('title')->sourceOf(new Article(['title' => 'О компании'])))->toBe('О компании')
        ->and(Slug::make('slug')->sourceOf(new Article(['title' => 'О компании'])))->toBeNull();
});
