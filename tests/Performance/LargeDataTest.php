<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;

/*
 * Замеры на больших данных: список на 100 000 записей и импорт 50 000 строк.
 * Не входят в composer check (группа performance исключена в phpunit.xml), запуск вручную:
 * vendor/bin/pest --group=performance
 */

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Выполнить запрос и напечатать время, число запросов к базе и пик памяти.
 *
 * @param  Closure(): TestResponse  $request
 * @return array{response: TestResponse, seconds: float, queries: int}
 */
function measure(string $title, Closure $request): array
{
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    gc_collect_cycles();
    memory_reset_peak_usage();
    $start = hrtime(true);

    $response = $request();

    $seconds = (hrtime(true) - $start) / 1e9;
    $memory = memory_get_peak_usage() / 1024 / 1024;
    fwrite(STDERR, sprintf("\n  %-40s %7.2f с  %5d запросов  %6.1f МБ", $title, $seconds, $queries, $memory));

    return ['response' => $response, 'seconds' => $seconds, 'queries' => $queries];
}

/**
 * Вставить статьи пачками в обход моделей (быстро, как миграция данных).
 */
function seedArticles(int $count): void
{
    $now = now()->toDateTimeString();
    $categories = collect(range(1, 20))->map(fn (int $i): int => (int) DB::table('categories')->insertGetId([
        'title' => 'Категория '.$i, 'created_at' => $now, 'updated_at' => $now,
    ]));

    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        DB::table('articles')->insert(array_map(fn (int $i): array => [
            'title' => 'Статья '.$i,
            'slug' => 'statya-'.$i,
            'excerpt' => 'Анонс статьи номер '.$i,
            'status' => ['draft', 'published'][$i % 2],
            'views' => $i % 5000,
            'is_published' => $i % 2 === 0,
            'published_on' => now()->subDays($i % 365)->toDateString(),
            'category_id' => $categories[$i % 20],
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }
}

it('keeps the list of 100 000 records fast', function (): void {
    seedArticles(100_000);
    fwrite(STDERR, "\n  Список статей, 100 000 записей (".DB::connection()->getDriverName().'):');

    $pages = [
        'первая страница' => route('fastleo.resources.index', 'articles'),
        'поиск' => route('fastleo.resources.index', ['articles', 'q' => 'Статья 99999']),
        'сортировка по заголовку' => route('fastleo.resources.index', ['articles', 'sort' => 'title', 'direction' => 'desc']),
        'фильтры статус + категория' => route('fastleo.resources.index', ['articles', 'filter' => ['status' => 'published', 'category_id' => 3]]),
        'страница 3000' => route('fastleo.resources.index', ['articles', 'page' => 3000]),
        'все (max_all)' => route('fastleo.resources.index', ['articles', 'per_page' => 'all']),
        'корзина' => route('fastleo.resources.index', ['articles', 'trashed' => 1]),
        'дашборд' => route('fastleo.dashboard'),
        'поиск Ctrl+K' => route('fastleo.search', ['q' => 'Статья 5000']),
        'форма записи' => route('fastleo.resources.edit', ['articles', 50_000]),
    ];

    foreach ($pages as $title => $url) {
        $result = measure($title, fn (): TestResponse => $this->get($url));
        $result['response']->assertOk();
        expect($result['queries'])->toBeLessThan(40);
    }

    $export = measure('экспорт csv', function (): TestResponse {
        $response = $this->get(route('fastleo.resources.export', ['articles', 'format' => 'csv']));
        $response->streamedContent();

        return $response;
    });
    $export['response']->assertOk();
    fwrite(STDERR, "\n");
});

it('imports 50 000 rows', function (): void {
    Storage::fake('local');
    $path = tempnam(sys_get_temp_dir(), 'fastleo-import').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, ['Заголовок', 'Статус', 'Просмотры', 'Анонс'], ';', escape: '');

    for ($i = 1; $i <= 50_000; $i++) {
        fputcsv($file, ['Импорт '.$i, 'опубликовано', (string) $i, 'Анонс '.$i], ';', escape: '');
    }

    fclose($file);
    // Как в настоящем импорте — без внешней транзакции теста: иначе на PostgreSQL тысячи точек
    // сохранения в одной транзакции исчерпывают max_locks_per_transaction. База очищается при следующем запуске.
    DB::commit();
    fwrite(STDERR, "\n  Импорт 50 000 строк (".DB::connection()->getDriverName().', очередь sync):');

    measure('импорт csv', fn (): TestResponse => $this->post(route('fastleo.resources.import', 'articles'), [
        'file' => new UploadedFile($path, 'import.csv', null, null, true),
    ]))['response']->assertRedirect();
    fwrite(STDERR, "\n");

    expect(Import::query()->latest('id')->firstOrFail()->only('status', 'created', 'skipped'))->toBe(['status' => 'done', 'created' => 50_000, 'skipped' => 0])
        ->and(Article::query()->count())->toBe(50_000);
});
