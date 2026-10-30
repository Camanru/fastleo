<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Policies\ImportingCategoryPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Imports');
    Storage::fake('local');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('accepts only xlsx and csv files of limited size', function (mixed $file): void {
    $this->post(route('fastleo.resources.import', 'articles'), ['file' => $file])->assertSessionHasErrors('file');

    expect(Import::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'php script' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
    'php hidden in a double extension' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('shell.csv.php', 'title'),
    'too big' => fn (): UploadedFile => UploadedFile::fake()->create('big.csv', 10241),
    'no file' => fn (): string => 'file',
]);

it('keeps the uploaded file off the public disk under a random name', function (): void {
    Storage::fake('public');
    Queue::fake();

    $this->post(route('fastleo.resources.import', 'articles'), ['file' => UploadedFile::fake()->createWithContent('../../evil.csv', "Заголовок\nx")]);

    expect(Import::query()->firstOrFail()->path)->toMatch('#^fastleo/imports/[0-9a-f-]{36}\.csv$#')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('fails a file that only pretends to be a spreadsheet', function (): void {
    $this->post(route('fastleo.resources.import', 'articles'), ['file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not a zip')]);

    expect(Import::query()->firstOrFail()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл не читается: нужен xlsx или csv.']);
});

it('ignores columns that are not fields of the resource', function (): void {
    $category = Category::factory()->create(['code' => 'news', 'internal_code' => 'secret']);

    $this->post(route('fastleo.resources.import', 'categories'), [
        'file' => UploadedFile::fake()->createWithContent('import.csv', "Код;Название;internal_code;id;parent_id\nnews;Новости;hacked;999;1\n"),
    ]);

    expect($category->fresh()?->only('title', 'internal_code', 'parent_id'))->toBe(['title' => 'Новости', 'internal_code' => 'secret', 'parent_id' => null]);
});

it('does not import by a GET request or without a csrf token', function (): void {
    // GET /articles/import — это адрес записи с ключом «import»: такой нет.
    $this->get(route('fastleo.resources.index', 'articles').'/import')->assertNotFound();

    $this->app['env'] = 'production';
    $this->post(route('fastleo.resources.import', 'articles'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "Заголовок\nx")])->assertStatus(419);

    expect(Import::query()->count())->toBe(0);
});

it('checks the rights of the form for every row even when the policy allows the import', function (): void {
    Gate::policy(Category::class, ImportingCategoryPolicy::class);
    $closed = Category::factory()->create(['code' => 'closed', 'title' => 'Закрыто']);

    $this->post(route('fastleo.resources.import', 'categories'), [
        'file' => UploadedFile::fake()->createWithContent('categories.csv', "Код;Название\nclosed;Взлом\nnew;Новая\n"),
    ]);

    expect(Import::query()->latest('id')->firstOrFail()->only('updated', 'created', 'skipped'))->toBe(['updated' => 0, 'created' => 0, 'skipped' => 2])
        ->and($closed->fresh()?->title)->toBe('Закрыто')
        ->and(Category::query()->count())->toBe(1);
});

it('does not import into a read-only section', function (): void {
    $resource = new class extends Resource
    {
        protected static string $model = Category::class;

        protected static bool $importable = true;

        protected static bool $readonly = true;

        public function fields(): array
        {
            return [];
        }
    };

    expect($resource::isImportable())->toBeFalse();
});

it('cleans the HTML of a RichText field like the form does', function (): void {
    $html = '<p onclick="alert(1)">Текст<script>alert(2)</script> <a href="javascript:alert(3)">ссылка</a></p>';

    $this->post(route('fastleo.resources.import', 'articles'), [
        'file' => UploadedFile::fake()->createWithContent('articles.csv', "Заголовок;Текст\nСтатья;\"".str_replace('"', '""', $html)."\"\n"),
    ]);

    $body = (string) Article::query()->where('title', 'Статья')->value('body');

    expect($body)->toContain('Текст')->toContain('ссылка')
        ->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:');
});
