<?php

declare(strict_types=1);

use Fastleo\Exports\Exporter;
use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    useFixtureResources('Exports');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Выгрузить раздел статей.
 *
 * @param  array<string, mixed>  $query
 */
function exportArticles(array $query = []): TestResponse
{
    return test()->get(route('fastleo.resources.export', ['resource' => 'articles', ...$query]));
}

it('exports the fields of the resource with their labels and readable values', function (): void {
    config(['fastleo.timezone' => 'Europe/Moscow', 'app.timezone' => 'UTC']);
    $category = Category::factory()->create();
    $topics = Topic::factory()->count(2)->create();
    $article = Article::factory()->published()->create([
        'title' => 'Отчёт',
        'category_id' => $category->id,
        'tags' => ['news', 'promo'],
        'keywords' => ['акция', 'новинка'],
        'views' => 1500,
        'price' => '12.50',
        'published_on' => '2026-03-08',
        'starts_at' => '2026-03-07 22:30:00',
        'gallery' => ['uploads/a.jpg', 'uploads/b.jpg'],
    ]);
    $article->topics()->attach($topics);

    $response = exportArticles()->assertOk()->assertDownload();

    expect($response->headers->get('content-disposition'))->toMatch('/articles-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx/')
        ->and(exportedRows($response))->toBe([
            ['id', 'Заголовок', 'Статус', 'Категория', 'Темы', 'Опубликовано', 'Метки', 'Ключевые слова', 'Просмотры', 'Цена', 'Дата публикации', 'На главной с', 'Галерея'],
            [$article->id, 'Отчёт', 'Опубликовано', (string) $category->id, $topics[0]->id.', '.$topics[1]->id, 'Да', 'Новости, Акции', 'акция, новинка', 1500, 12.5, '2026-03-08', '2026-03-08 01:30', "uploads/a.jpg\nuploads/b.jpg"],
        ]);
});

it('exports what the list shows on every page', function (): void {
    Article::factory()->published()->count(30)->create();
    Article::factory()->count(5)->create(['is_published' => false]);

    $rows = exportedRows(exportArticles(['filter' => ['is_published' => '1'], 'page' => 2]));

    expect($rows)->toHaveCount(31);
});

it('keeps text that looks like a number or a formula as text', function (): void {
    Article::factory()->create(['title' => '007', 'status' => ArticleStatus::Draft]);
    Article::factory()->create(['title' => '=HYPERLINK("http://evil","x")']);

    $response = exportArticles();
    $path = tempnam(sys_get_temp_dir(), 'fastleo-export');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    File::delete($path);

    expect(array_column(array_slice(exportedRows($response), 1), 1))->toContain('007')->toContain('=HYPERLINK("http://evil","x")')
        ->and($sheet)->not->toContain('<f>');
});

it('writes a csv for Russian Excel and defuses formulas there', function (): void {
    Article::factory()->create(['title' => '=1+2', 'price' => -5]);
    Article::factory()->create(['title' => '-Скидка', 'price' => 10]);

    $response = exportArticles(['format' => 'csv'])->assertOk();
    $content = $response->streamedContent();
    $rows = exportedRows($response, 'csv');
    $price = array_search('Цена', $rows[0], true);

    expect($content)->toStartWith("\u{FEFF}id;")
        ->and(array_column(array_slice($rows, 1), 1))->toEqualCanonicalizing(["'=1+2", "'-Скидка"])
        ->and(array_column(array_slice($rows, 1), $price))->toEqualCanonicalizing(['-5', '10']);
});

it('exports the trash separately', function (): void {
    Article::factory()->create(['title' => 'Живая']);
    Article::factory()->create(['title' => 'Удалённая'])->delete();

    expect(array_column(array_slice(exportedRows(exportArticles(['trashed' => '1'])), 1), 1))->toBe(['Удалённая']);
});

it('needs the right to see the section', function (): void {
    Gate::policy(Article::class, HiddenCategoryPolicy::class);

    exportArticles()->assertForbidden();
});

it('is offered in the list and not for a section that turned it off', function (): void {
    Topic::factory()->create();

    $this->get(route('fastleo.resources.index', 'articles'))->assertSee(route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'csv']), false);
    $this->get(route('fastleo.resources.index', 'topics'))->assertOk()->assertDontSee('/export', false);
    $this->get(route('fastleo.resources.export', 'topics'))->assertNotFound();
});

it('keeps the export link on its own section whatever the query of the list says', function (array|string $other): void {
    $this->get(route('fastleo.resources.index', 'articles').'?'.http_build_query(['resource' => $other, 'format' => 'csv']))
        ->assertOk()
        ->assertSee('href="'.route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'xlsx']).'"', false);
})->with(['another section' => 'topics', 'array' => [['x']]]);

describe('large tables', function (): void {
    /**
     * Строки выгрузки запроса csv-файлом без заголовка: первый столбец — ключ.
     *
     * @param  Builder<Article>  $query
     * @return list<int>
     */
    function exportedKeys(Builder $query): array
    {
        $resource = Fastleo::resources()->find('articles');
        $response = new Exporter($resource)->download($query, 'csv');
        ob_start();
        $response->sendContent();
        $lines = array_slice(explode("\n", trim((string) ob_get_clean())), 1);
        $rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), $lines);

        return array_map(fn (array $row): int => (int) $row[0], $rows);
    }

    it('keeps the order of the list across chunks of 500 records', function (): void {
        $now = now()->toDateTimeString();
        $rows = array_map(fn (int $i): array => ['title' => 'Статья '.$i, 'views' => ($i * 7919) % 1237, 'created_at' => $now, 'updated_at' => $now], range(1, 1201));

        foreach (array_chunk($rows, 300) as $chunk) {
            Article::query()->insert($chunk);
        }

        $expected = Article::query()->orderByDesc('views')->orderBy('id')->pluck('id')->all();

        expect(exportedKeys(Article::query()->orderByDesc('views')->orderBy('id')))->toBe($expected)
            ->and($expected)->toHaveCount(1201);
    });

    it('writes a record once when the query of the resource multiplies its rows with a join', function (): void {
        $articles = Article::factory()->count(3)->create();

        foreach ($articles as $article) {
            $article->links()->createMany([['title' => 'A', 'url' => 'https://a.example'], ['title' => 'B', 'url' => 'https://b.example']]);
        }

        $keys = exportedKeys(Article::query()->join('article_links', 'article_links.article_id', '=', 'articles.id')->select('articles.*')->orderBy('articles.id'));

        expect($keys)->toBe($articles->pluck('id')->all());
    });

    it('does not export records the query left out', function (): void {
        $kept = Article::factory()->create(['views' => 10]);
        Article::factory()->create(['views' => 1]);
        Article::factory()->create(['title' => 'Удалённая', 'views' => 10])->delete();

        expect(exportedKeys(Article::query()->where('views', '>', 5)))->toBe([$kept->id]);
    });
});
