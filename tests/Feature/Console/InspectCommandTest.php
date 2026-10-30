<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Fastleo\ArticleResource;
use Workbench\App\Fastleo\ContactsSettings;
use Workbench\App\Fastleo\ReportPage;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Policies\ArticlePolicy;

/**
 * Результат `fastleo:inspect --json` как массив.
 *
 * @return array<string, mixed>
 */
function inspected(string $arguments = ''): array
{
    Artisan::call(trim('fastleo:inspect '.$arguments).' --json');

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('describes a resource: model, policy, fields, filters and actions', function (): void {
    $articles = inspected('articles');

    expect($articles)->toMatchArray([
        'slug' => 'articles',
        'class' => ArticleResource::class,
        'model' => Article::class,
        'table' => 'articles',
        'policy' => ArticlePolicy::class,
        'readonly' => false,
        'soft_deletes' => true,
    ])
        ->and(array_column($articles['filters'], 'name'))->toContain('status', 'category_id')
        ->and(array_column($articles['actions'], 'key'))->toContain('delete', 'publish-articles');
});

it('describes fields: rules, relation, files and enum options', function (): void {
    $fields = collect(inspected('articles')['fields'])->keyBy('name');

    expect($fields['title'])->toMatchArray(['type' => 'text', 'required' => true, 'max_length' => 255])
        ->and($fields['title']['rules']['title'])->toBe(['required', 'string', 'max:255'])
        ->and($fields['category_id'])->toMatchArray(['type' => 'belongs-to', 'relation' => 'category', 'related_model' => Category::class])
        ->and($fields['cover']['extensions'])->toContain('jpg')
        ->and($fields['cover']['folder'])->toBeString()
        ->and($fields['status']['rules']['status'])->toContain('required')
        ->and($fields['links']['fields'])->not->toBeEmpty()
        ->and($fields['tags']['multiple'])->toBeTrue();
});

it('lists resources, settings and custom pages without arguments', function (): void {
    $all = inspected();

    expect(array_column($all['resources'], 'slug'))->toContain('articles', 'categories')
        ->and(collect($all['settings'])->firstWhere('class', ContactsSettings::class)['fields'])->not->toBeEmpty()
        ->and(collect($all['pages'])->firstWhere('class', ReportPage::class))->toHaveKeys(['slug', 'label', 'view']);
});

it('prints readable tables without --json', function (): void {
    $this->artisan('fastleo:inspect articles')
        ->expectsOutputToContain('Статьи (articles)')
        ->expectsOutputToContain('required|string|max:255')
        ->assertSuccessful();
});

it('says whether the section is visible to an AI agent and why not', function (): void {
    useFixtureResources('McpPersonal');

    expect(inspected('plain'))->toMatchArray(['mcp' => true])
        ->and(inspected('phone-field'))->toMatchArray(['mcp' => true])
        ->and(inspected('closed'))->toMatchArray(['mcp' => false])
        ->and(inspected('closed'))->not->toHaveKey('mcp_personal_field');

    $this->artisan('fastleo:inspect closed')->expectsOutputToContain('закрыт ($mcp = false)')->assertSuccessful();
});

it('fails with the list of sections for an unknown slug', function (): void {
    $this->artisan('fastleo:inspect nothing')
        ->expectsOutputToContain('Раздела «nothing» нет. Доступные адреса')
        ->assertFailed();
});

it('reports a broken resource description instead of crashing', function (): void {
    useFixtureResources('Inspect');

    $broken = inspected('categories');

    expect($broken)->toHaveKey('error')->and($broken)->not->toHaveKey('fields');

    $this->artisan('fastleo:inspect')->expectsOutputToContain('BrokenResource')->assertSuccessful();
});
