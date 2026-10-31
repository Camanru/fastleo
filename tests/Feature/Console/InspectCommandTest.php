<?php

declare(strict_types=1);

use Fastleo\Filters\SelectFilter;
use Fastleo\Tests\Fixtures\Related\ArticleResource as RelatedArticleResource;
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

it('describes how the section sits in the menu: group, icon, position, a visible item and a counter', function (): void {
    $articles = inspected('articles');

    expect($articles)->toMatchArray(['group' => 'Контент', 'icon' => 'newspaper', 'position' => 10, 'in_menu' => true, 'has_badge' => false])
        ->and(inspected('feedback')['has_badge'])->toBeTrue();
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

it('shows what a field writes instead of an empty value', function (): void {
    useFixtureResources('EmptyValues');

    $fields = collect(inspected('empty-values')['fields'])->keyBy('name');

    expect($fields['type']['empty_value'])->toBe('none')
        ->and($fields['views']['empty_value'])->toBe(0)
        ->and($fields['phone'])->not->toHaveKey('empty_value')
        ->and($fields['type']['rules']['type'])->toContain('in:"news","promo"');
});

it('marks the fields drawn as colored badges', function (): void {
    useFixtureResources('Badges');

    $fields = collect(inspected('badges')['fields'])->keyBy('name');

    expect($fields['status']['badges'])->toBeTrue()
        ->and($fields['type']['badges'])->toBeTrue()
        ->and($fields['title'])->not->toHaveKey('badges');
});

it('says which filters open with a default value', function (): void {
    useFixtureResources('FilterDefaults');

    expect(inspected('status-default')['filters'])->toBe([[
        'name' => 'status',
        'label' => 'Статус',
        'class' => SelectFilter::class,
        'has_default' => true,
    ]])
        ->and(inspected('period-default')['filters'][0]['has_default'])->toBeTrue();
});

it('marks the fields that are edited in the cell of the list', function (): void {
    useFixtureResources('Inline');

    $fields = collect(inspected('inline-articles')['fields'])->keyBy('name');

    expect($fields['views']['inline_editable'])->toBeTrue()
        ->and($fields['title'])->not->toHaveKey('inline_editable');
});

it('marks the columns that come from the query of the resource', function (): void {
    useFixtureResources('FromQuery');

    $fields = collect(inspected('categories')['fields'])->keyBy('name');

    expect($fields['articles_count'])->toMatchArray(['from_query' => true, 'writes_attribute' => false, 'in_form' => false, 'in_list' => true, 'sortable' => true])
        ->and($fields['title'])->not->toHaveKey('from_query');
});

it('lists the related tables of a section', function (): void {
    useFixtureResources('Related');

    expect(inspected('categories')['relations'])->toBe([[
        'name' => 'articles',
        'label' => 'Статьи рубрики',
        'resource' => RelatedArticleResource::class,
        'slug' => 'articles',
        'foreign_key' => 'category_id',
        'expandable' => false,
        'aside' => false,
        'columns' => ['title', 'status'],
        'quick_add' => [],
        'add_label' => 'Добавить',
    ]])
        ->and(inspected('articles')['relations'])->toBe([]);
});

it('tells the fields of the quick add form and the label of the button', function (): void {
    useFixtureResources('QuickAdd');

    expect(inspected('categories')['relations'][0])->toMatchArray(['quick_add' => ['title', 'views'], 'add_label' => 'Добавить статью']);
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

it('prints custom pages with their view when no section is named', function (): void {
    $this->artisan('fastleo:inspect')
        ->expectsOutputToContain('Страница '.ReportPage::label().' ('.ReportPage::slug().')')
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

it('reports a broken settings page and a field with failing rules instead of crashing', function (): void {
    useFixtureResources('InspectBroken');

    $all = inspected();

    expect(collect($all['settings'])->firstWhere('slug', 'broken'))->toHaveKey('error')->not->toHaveKey('fields')
        ->and(collect(inspected('broken-rules')['fields'])->firstWhere('name', 'code')['rules'])->toBe([])
        ->and(collect(inspected('broken-rules')['fields'])->firstWhere('name', 'title'))->toHaveKey('rules');
});

it('marks the checkboxes that switch right in the list', function (): void {
    useFixtureResources('QuickToggle');

    $fields = collect(inspected('articles')['fields'])->keyBy('name');

    expect($fields['is_published'])->toMatchArray(['quick_toggle' => true])
        ->and($fields['is_featured'])->not->toHaveKey('quick_toggle');
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
