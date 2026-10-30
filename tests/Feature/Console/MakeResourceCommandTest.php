<?php

declare(strict_types=1);

use Fastleo\Fields\Field;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\Ghost;
use Fastleo\Tests\Fixtures\Models\OpenUser;
use Fastleo\Tests\Fixtures\Models\Resource as ResourceModel;
use Fastleo\Tests\Fixtures\Models\SpatiePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Page;

beforeEach(function (): void {
    // Ресурсы пишутся во временную папку: каркас и workbench общие для параллельных процессов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-make-'.getmypid().'-'.uniqid();
    $this->namespace = 'Generated'.uniqid();
    config(['fastleo.resources.path' => $this->sandbox, 'fastleo.resources.namespace' => $this->namespace]);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

/**
 * Загрузить созданный ресурс и вернуть его поля: имя → «Тип: правила».
 *
 * @return array<string, string>
 */
function generatedFields(string $path, string $class): array
{
    require_once $path;
    /** @var Resource $resource */
    $resource = new $class;

    return collect($resource->schema()->fields())
        ->mapWithKeys(fn (Field $field): array => [$field->name => class_basename($field).': '.implode('|', array_map(
            fn (mixed $rule): string => $rule instanceof Stringable || is_string($rule) ? (string) $rule : class_basename($rule),
            $field->getRules(null)[$field->name],
        ))])
        ->all();
}

it('builds fields from the table columns', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Article', '--model' => Article::class, '--generate' => true])
        ->expectsOutputToContain('Поля собраны по таблице articles')
        ->assertSuccessful();

    $fields = generatedFields($this->sandbox.'/ArticleResource.php', $this->namespace.'\\ArticleResource');

    expect($fields)->toMatchArray([
        'title' => 'Text: required|string|max:255',
        'excerpt' => 'Textarea: nullable|string',
        'views' => 'Number: nullable|integer',
        'price' => 'Number: nullable|numeric',
        'is_published' => 'Toggle: nullable|boolean',
        'published_on' => 'Date: nullable|date_format:Y-m-d',
        'starts_at' => 'DateTime: nullable|date_format:Y-m-d\TH:i',
        'opens_at' => 'Time: nullable|date_format:H:i',
        'author_email' => 'Email: nullable|string|max:255|email',
        'source_url' => 'Url: nullable|string|max:255|url:http,https',
        'phone' => 'Tel: nullable|string|max:32',
        'color' => 'Color: nullable|hex_color',
    ])->not->toHaveKeys(['id', 'created_at', 'updated_at', 'deleted_at']);
    expect(File::get($this->sandbox.'/ArticleResource.php'))
        ->toContain("Text::make('title', 'Title')->required()")
        ->toContain("protected static ?string \$label = 'Articles';")
        ->and($fields['type'])->toStartWith('Text: nullable|string|max:')
        ->and(substr_count(File::get($this->sandbox.'/ArticleResource.php'), '->searchable()->sortable()'))->toBe(1);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect($fields['type'])->toBe('Text: nullable|string|max:20'); // SQLite не хранит длину varchar
    }
});

it('imports a model named like a class of the package under another name', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Page', '--model' => ResourceModel::class, '--generate' => true])->assertSuccessful();
    $code = File::get($this->sandbox.'/PageResource.php');

    expect($code)
        ->toContain('use Fastleo\\Resource;')
        ->toContain('use '.ResourceModel::class.' as ResourceModel;')
        ->toContain('protected static string $model = ResourceModel::class;');

    // Файл подключается без фатальной ошибки «имя Resource уже используется».
    expect(generatedFields($this->sandbox.'/PageResource.php', $this->namespace.'\\PageResource'))->toBeArray();
});

it('finds relations, the tree, the manual order and the slug', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Category', '--model' => Category::class, '--generate' => true])->assertSuccessful();
    $category = File::get($this->sandbox.'/CategoryResource.php');

    expect($category)
        ->toContain("protected static ?string \$sortColumn = 'sort';")
        ->toContain("protected static ?string \$treeColumn = 'parent_id';")
        ->toContain("BelongsTo::make('parent_id', 'Parent')")
        ->not->toContain("make('sort'");

    $this->artisan('fastleo:make-resource', ['name' => 'Article', '--model' => Article::class, '--generate' => true, '--force' => true])->assertSuccessful();
    $article = File::get($this->sandbox.'/ArticleResource.php');

    expect($article)
        ->toContain("BelongsTo::make('category_id', 'Category')")
        ->toContain("BelongsTo::make('subcategory_id', 'Subcategory')")
        ->toContain("Slug::make('slug', 'Slug')->from('title')")
        ->not->toContain('$treeColumn')
        ->and(generatedFields($this->sandbox.'/ArticleResource.php', $this->namespace.'\\ArticleResource'))->toMatchArray([
            'category_id' => 'BelongsTo: nullable|integer|OptionRule',
        ]);
});

it('recognises pictures, files, a gallery and rich text by the column name', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Article', '--model' => Article::class, '--generate' => true])->assertSuccessful();

    expect(File::get($this->sandbox.'/ArticleResource.php'))
        ->toContain("Image::make('cover', 'Cover')")
        ->toContain("File::make('attachment', 'Attachment')")
        ->toContain("Gallery::make('gallery', 'Gallery')->hideInList()")
        ->toContain("RichText::make('content', 'Content')")
        ->not->toContain("RichText::make('content', 'Content')->hideInList()")
        ->toContain("Textarea::make('head_code', 'Head code')")
        ->and(generatedFields($this->sandbox.'/ArticleResource.php', $this->namespace.'\\ArticleResource'))->toMatchArray([
            'cover' => 'Image: nullable|string|FilePathRule',
            'gallery' => 'Gallery: nullable|array|max:100',
        ]);
});

it('skips columns the model does not allow to fill', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Category', '--model' => Category::class, '--generate' => true])
        ->expectsOutputToContain('Колонка internal_code не в $fillable модели')
        ->assertSuccessful();

    expect(generatedFields($this->sandbox.'/CategoryResource.php', $this->namespace.'\\CategoryResource'))
        ->toHaveKeys(['title', 'code'])
        ->not->toHaveKey('internal_code');
});

it('never generates the admin access flag or the password', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'OpenUser', '--model' => OpenUser::class, '--generate' => true])->assertSuccessful();

    expect(File::get($this->sandbox.'/OpenUserResource.php'))
        ->toContain("Text::make('name'")
        ->toContain("Email::make('email'")
        ->not->toContain('fastleo_admin')
        ->not->toContain("'password'");
});

it('creates an empty resource without --generate', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'CategoryResource', '--model' => Category::class])->assertSuccessful();

    expect(File::get($this->sandbox.'/CategoryResource.php'))
        ->toContain('return [];')
        ->toContain('use '.Category::class.';')
        ->toContain('final class CategoryResource extends Resource');
});

it('does not overwrite an existing file without --force', function (): void {
    File::ensureDirectoryExists($this->sandbox);
    File::put($this->sandbox.'/CategoryResource.php', 'своё');

    $this->artisan('fastleo:make-resource', ['name' => 'Category', '--model' => Category::class])
        ->expectsOutputToContain('уже есть')
        ->assertFailed();
    expect(File::get($this->sandbox.'/CategoryResource.php'))->toBe('своё');

    $this->artisan('fastleo:make-resource', ['name' => 'Category', '--model' => Category::class, '--force' => true])->assertSuccessful();
    expect(File::get($this->sandbox.'/CategoryResource.php'))->toContain('CategoryResource');
});

it('rejects a wrong name, an unknown model and a missing table', function (array $arguments, string $message): void {
    $this->artisan('fastleo:make-resource', $arguments)->expectsOutputToContain($message)->assertFailed();

    expect($this->sandbox)->not->toBeDirectory();
})->with([
    'name' => [['name' => 'Врач'], 'не подходит'],
    'model' => [['name' => 'Doctor'], 'Модель App\Models\Doctor не найдена'],
    'table' => [['name' => 'Ghost', '--model' => Ghost::class, '--generate' => true], 'Таблицы ghosts нет в базе'],
]);

it('asks for the name when it is not given', function (): void {
    $this->artisan('fastleo:make-resource', ['--model' => Category::class])
        ->expectsQuestion('Имя раздела латиницей, например Doctor', 'Category')
        ->assertSuccessful();

    expect($this->sandbox.'/CategoryResource.php')->toBeFile();
});

it('recognises the search engine column by the name and the array cast', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Article', '--model' => Article::class, '--generate' => true])->assertSuccessful();

    expect(File::get($this->sandbox.'/ArticleResource.php'))
        ->toContain("Seo::make('seo', 'SEO'),")
        ->toContain('use Fastleo\\Fields\\Seo;')
        ->and(generatedFields($this->sandbox.'/ArticleResource.php', $this->namespace.'\\ArticleResource'))->toMatchArray(['seo' => 'Seo: nullable|array']);
});

it('recognises key value pairs and translatable attributes of spatie models', function (): void {
    $this->artisan('fastleo:make-resource', ['name' => 'Page', '--model' => Page::class, '--generate' => true])
        ->expectsOutputToContain('Колонка faq')
        ->assertSuccessful();

    expect(File::get($this->sandbox.'/PageResource.php'))->toContain("KeyValue::make('specs', 'Specs')")->not->toContain("'title'")->not->toContain("'content'");

    $this->artisan('fastleo:make-resource', ['name' => 'SpatiePage', '--model' => SpatiePage::class, '--generate' => true])->assertSuccessful();

    expect(File::get($this->sandbox.'/SpatiePageResource.php'))->toContain("Text::make('title', 'Title')->translatable()->required()->searchable()->sortable()");
});
