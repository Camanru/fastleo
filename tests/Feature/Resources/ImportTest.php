<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Imports\ImportRecords;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Tests\Fixtures\Policies\RestrictedCategoryPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useFixtureResources('Imports');
    Storage::fake('local');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Файл xlsx из строк.
 *
 * @param  list<list<mixed>>  $rows
 */
function xlsxFile(array $rows, string $name = 'import.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fastleo-xlsx').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(fn (mixed $value): Cell => match (true) {
            is_string($value) => new Cell\StringCell($value),
            // Дата в Excel — число с форматом даты: так её сохраняет сам Excel.
            $value instanceof DateTimeInterface => new Cell\DateTimeCell($value, new Style(format: 'dd.mm.yyyy')),
            default => Cell::fromValue($value),
        }, $row)));
    }

    $writer->close();

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Загрузить файл в раздел.
 */
function importFile(UploadedFile $file, string $resource = 'articles'): TestResponse
{
    return test()->post(route('fastleo.resources.import', $resource), ['file' => $file]);
}

/**
 * Последний импорт.
 */
function lastImport(): Import
{
    return Import::query()->latest('id')->firstOrFail();
}

it('creates and updates records and shows the report', function (): void {
    $article = Article::factory()->create(['title' => 'Старый', 'views' => 1]);

    importFile(xlsxFile([
        ['id', 'Заголовок', 'Просмотры'],
        [$article->id, 'Обновлённый', 10],
        ['', 'Новая', 5],
    ]))->assertRedirect(route('fastleo.resources.imports.show', ['articles', lastImport()->id]));

    expect(lastImport()->only('status', 'processed', 'created', 'updated', 'skipped'))->toBe(['status' => 'done', 'processed' => 2, 'created' => 1, 'updated' => 1, 'skipped' => 0])
        ->and($article->fresh()?->only('title', 'views'))->toBe(['title' => 'Обновлённый', 'views' => 10])
        ->and(Article::query()->where('title', 'Новая')->first()?->only('views', 'status'))->toBe(['views' => 5, 'status' => ArticleStatus::Draft])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->get(route('fastleo.resources.imports.show', ['articles', lastImport()->id]))
        ->assertOk()
        ->assertSee('Загрузка завершена');
});

it('understands labels, yes and no, lists and Russian dates', function (): void {
    $topics = Topic::factory()->count(2)->create();

    importFile(xlsxFile([
        ['Заголовок', 'Статус', 'Опубликовано', 'Метки', 'Ключевые слова', 'Дата публикации', 'На главной с', 'Темы'],
        ['Статья', 'опубликовано', 'Да', 'Новости, promo', 'акция, новинка', '08.03.2026', '08.03.2026 14:30', $topics[0]->id.', '.$topics[1]->id],
    ]));

    $article = Article::query()->where('title', 'Статья')->firstOrFail();

    expect($article->status)->toBe(ArticleStatus::Published)
        ->and($article->is_published)->toBeTrue()
        ->and($article->tags)->toBe(['news', 'promo'])
        ->and($article->keywords)->toBe(['акция', 'новинка'])
        ->and($article->published_on?->toDateString())->toBe('2026-03-08')
        ->and((string) $article->getRawOriginal('starts_at'))->toBe('2026-03-08 14:30:00')
        ->and($article->topics()->pluck('topics.id')->sort()->values()->all())->toBe($topics->pluck('id')->sort()->values()->all());
});

it('takes excel dates as they are', function (): void {
    importFile(xlsxFile([
        ['Заголовок', 'Дата публикации'],
        ['Статья', new DateTimeImmutable('2026-03-08')],
    ]));

    expect(Article::query()->firstOrFail()->published_on?->toDateString())->toBe('2026-03-08');
});

it('skips bad rows with the reason and saves the good ones', function (): void {
    importFile(xlsxFile([
        ['Заголовок', 'Просмотры', 'Статус'],
        ['', 5, 'draft'],
        ['Хорошая', 5, 'draft'],
        ['Отрицательная', -1, 'draft'],
        ['Неизвестный статус', 1, 'archived'],
    ]));

    expect(lastImport()->only('created', 'skipped'))->toBe(['created' => 1, 'skipped' => 3])
        ->and(lastImport()->errors)->toBe([
            ['row' => 2, 'message' => 'Заполните поле «Заголовок».'],
            ['row' => 4, 'message' => 'Поле «Просмотры» должно быть не меньше 0.'],
            ['row' => 5, 'message' => 'Выбрано недопустимое значение поля «Статус».'],
        ])
        ->and(Article::query()->pluck('title')->all())->toBe(['Хорошая']);
});

it('skips empty rows silently and reports unknown columns', function (): void {
    importFile(xlsxFile([
        ['Заголовок', 'Взлом', 'id'],
        ['Первая', 'x', ''],
        ['', '', ''],
        ['', 'только неизвестное', ''],
        ['Вторая', '', ''],
    ]));

    expect(lastImport()->only('processed', 'created', 'skipped', 'unknown_columns'))
        ->toBe(['processed' => 2, 'created' => 2, 'skipped' => 0, 'unknown_columns' => ['Взлом']]);
});

it('fails a file without known columns', function (): void {
    importFile(xlsxFile([['Что-то', 'Ещё'], ['1', '2']]));

    expect(lastImport()->status)->toBe('failed')
        ->and(lastImport()->message)->toContain('нет ни одного известного столбца')
        ->and(Article::query()->count())->toBe(0);
});

it('does not create a record for an id that is not in the section', function (): void {
    importFile(xlsxFile([['id', 'Заголовок'], ['999', 'Призрак']]));

    expect(lastImport()->errors)->toBe([['row' => 2, 'message' => 'Записи с ключом 999 нет.']])
        ->and(Article::query()->count())->toBe(0);
});

it('matches records by its own key, creates missing ones at the end of the order', function (): void {
    Category::factory()->create(['code' => 'news', 'title' => 'Старое', 'sort' => 1]);
    Category::factory()->create(['code' => 'other', 'sort' => 2]);

    importFile(xlsxFile([['Код', 'Название'], ['news', 'Новости'], ['promo', 'Акции']]), 'categories');

    expect(lastImport()->only('created', 'updated'))->toBe(['created' => 1, 'updated' => 1])
        ->and(Category::query()->where('code', 'news')->value('title'))->toBe('Новости')
        ->and(Category::query()->where('code', 'promo')->value('sort'))->toBe(3);
});

it('does not create an empty record for a row that has only a key nobody has', function (): void {
    Category::factory()->create(['code' => 'news', 'title' => 'Старое', 'sort' => 1]);

    importFile(xlsxFile([['Код', 'Название'], ['news', 'Новости'], ['ghost', ''], ['promo', 'Акции']]), 'categories');

    expect(lastImport()->only('created', 'updated', 'skipped'))->toBe(['created' => 1, 'updated' => 1, 'skipped' => 1])
        ->and(lastImport()->errors)->toBe([['row' => 3, 'message' => 'Записи с ключом ghost нет.']])
        ->and(Category::query()->where('code', 'ghost')->exists())->toBeFalse();
});

it('explains a database error of a row and a save cancelled by the model', function (): void {
    Article::saving(function (Article $article): ?bool {
        if ($article->title === 'Дубль') {
            throw new UniqueConstraintViolationException('testing', 'insert', [], new PDOException('UNIQUE constraint failed'));
        }

        return $article->title === 'Отмена' ? false : null;
    });

    importFile(xlsxFile([['Заголовок'], ['Дубль'], ['Отмена'], ['Обычная']]));

    expect(lastImport()->only('created', 'skipped'))->toBe(['created' => 1, 'skipped' => 2])
        ->and(array_column(lastImport()->errors ?? [], 'row'))->toBe([2, 3]);
});

it('keeps text that is longer than an excel cell when its own export comes back', function (): void {
    $long = str_repeat('z', 40000); // латиница: в TEXT MySQL помещается 65 535 байт
    $article = Article::factory()->create(['excerpt' => $long]);

    importFile(xlsxFile([['id', 'Анонс'], [$article->id, mb_substr($long, 0, 32767)]]));

    expect($article->fresh()?->excerpt)->toBe($long);
});

it('keeps text that looks like a number', function (): void {
    importFile(xlsxFile([['Заголовок', 'Анонс'], ['007', '12.50']]));

    expect(Article::query()->firstOrFail()->only('title', 'excerpt'))->toBe(['title' => '007', 'excerpt' => '12.50']);
});

it('finds records by its own key in a read-only field and does not create records without it', function (): void {
    $news = Category::factory()->create(['code' => 'news', 'title' => 'Старое']);

    importFile(xlsxFile([['Код', 'Название'], ['news', 'Новости'], ['promo', 'Акции']]), 'locked-categories');

    expect(lastImport()->only('created', 'updated', 'unknown_columns', 'errors'))->toBe([
        'created' => 0, 'updated' => 1, 'unknown_columns' => null, 'errors' => [['row' => 3, 'message' => 'Записи с ключом promo нет.']],
    ])->and($news->fresh()?->only('code', 'title'))->toBe(['code' => 'news', 'title' => 'Новости'])
        ->and(Category::query()->count())->toBe(1);
});

it('checks the visibility of a field by the stored value when the file has no controlling column', function (): void {
    $video = Article::factory()->create(['type' => 'video', 'source_url' => 'https://example.com/old']);

    importFile(xlsxFile([['id', 'Ссылка на видео'], [$video->id, 'https://example.com/new']]));

    expect($video->fresh()?->source_url)->toBe('https://example.com/new');
});

it('does not change read-only fields', function (): void {
    $article = Article::factory()->create(['author_email' => 'old@example.com']);

    importFile(xlsxFile([['id', 'Email автора', 'Заголовок'], [$article->id, 'new@example.com', 'Новый']]));

    expect($article->fresh()?->only('author_email', 'title'))->toBe(['author_email' => 'old@example.com', 'title' => 'Новый']);
});

it('asks the policy about every row', function (): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);
    $open = Category::factory()->create(['code' => 'open', 'title' => 'Открыто']);
    $closed = Category::factory()->create(['code' => 'closed', 'title' => 'Закрыто']);

    importFile(xlsxFile([['Код', 'Название'], ['open', 'Можно'], ['closed', 'Нельзя'], ['new', 'Новая']]), 'categories');

    expect(lastImport()->only('updated', 'skipped'))->toBe(['updated' => 1, 'skipped' => 2])
        ->and(array_column(lastImport()->errors ?? [], 'message'))->toBe(['Нет прав на эту запись.', 'Нет прав на эту запись.'])
        ->and($open->fresh()?->title)->toBe('Можно')
        ->and($closed->fresh()?->title)->toBe('Закрыто');
});

it('reads a csv saved by Russian Excel in Windows-1251', function (): void {
    $csv = mb_convert_encoding("Заголовок;Ключевые слова\nОтчёт;акция, новинка\n", 'Windows-1251', 'UTF-8');

    importFile(UploadedFile::fake()->createWithContent('import.csv', $csv));

    expect(Article::query()->firstOrFail()->only('title', 'keywords'))->toBe(['title' => 'Отчёт', 'keywords' => ['акция', 'новинка']]);
});

it('reads back its own csv export with the formula guard', function (): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', "\u{FEFF}id;Заголовок\n;'=1+2\n;\"Обычный, с запятой\"\n"));

    expect(Article::query()->orderBy('id')->pluck('title')->all())->toBe(['=1+2', 'Обычный, с запятой']);
});

it('stops after the row limit and says so', function (): void {
    config(['fastleo.import.max_rows' => 2]);

    importFile(xlsxFile([['Заголовок'], ['Один'], ['Два'], ['Три']]));

    expect(lastImport()->only('created', 'message'))->toBe(['created' => 2, 'message' => 'Обработаны первые 2 строк — остальные не загружены.']);
});

it('queues the import when the site has a queue', function (): void {
    Queue::fake();

    importFile(xlsxFile([['Заголовок'], ['Одна']]));

    Queue::assertPushed(ImportRecords::class, fn (ImportRecords $job): bool => $job->importId === lastImport()->id);
    expect(lastImport()->status)->toBe('pending')
        ->and(Storage::disk('local')->exists(lastImport()->path))->toBeTrue();
});

it('reports the progress as json to its author only', function (): void {
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();

    $this->getJson(route('fastleo.resources.imports.show', ['articles', $import->id]))
        ->assertOk()
        ->assertJson(['status' => 'done', 'finished' => true, 'created' => 1]);

    $foreign = Import::query()->create(['user_id' => (string) admin()->id, 'resource' => 'articles', 'file_name' => 'x.xlsx', 'path' => 'x.xlsx']);
    $this->getJson(route('fastleo.resources.imports.show', ['articles', $foreign->id]))->assertForbidden();

    $this->getJson(route('fastleo.resources.imports.show', ['categories', $import->id]))->assertNotFound();
});

it('forgets old reports', function (): void {
    $this->travelTo(now()->subDays(40));
    importFile(xlsxFile([['Заголовок'], ['Давняя']]));
    $old = lastImport();
    $this->travelBack();

    importFile(xlsxFile([['Заголовок'], ['Свежая']]));

    expect(Import::query()->find($old->id))->toBeNull();
});

it('offers the import only in sections that allow it', function (): void {
    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('id="fastleo-import"', false)->assertDontSee('x-init="$el.click()"', false);

    // После ошибки файла окно загрузки открывается снова.
    $this->from(route('fastleo.resources.index', 'articles'))->post(route('fastleo.resources.import', 'articles'), ['file' => 'x']);
    $this->get(route('fastleo.resources.index', 'articles'))->assertSee('x-init="$el.click()"', false);

    useFixtureResources('Exports');
    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertDontSee('id="fastleo-import"', false);
    importFile(xlsxFile([['Заголовок'], ['Одна']]))->assertNotFound();
});

it('fails an import whose section or author is gone', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    $import->update(['user_id' => '999999']);

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Раздел или пользователь больше недоступен.'])
        ->and(Storage::disk('local')->exists($import->path))->toBeFalse();
});

it('fails an import whose author lost access to the admin panel', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    User::query()->whereKey($import->user_id)->update(['fastleo_admin' => false]);

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Раздел или пользователь больше недоступен.'])
        ->and(Article::query()->count())->toBe(0);
});

it('does not leave the author signed in on a worker after the job', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    Fastleo::guard()->forgetUser();

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->status)->toBe('done')
        ->and(Fastleo::guard()->hasUser())->toBeFalse();
});

it('keeps the admin signed in when the job runs inside his own request', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();

    new ImportRecords($import->id)->handle();

    expect(Fastleo::guard()->hasUser())->toBeTrue();
});

it('marks an import interrupted by the worker as failed, removes the file and tells the author', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    $import->update(['status' => 'running']);

    new ImportRecords($import->id)->failed(new RuntimeException('timeout'));

    expect($import->fresh()?->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Загрузка прервалась из-за ошибки на сервере. Подробности — в журнале сайта.'])
        ->and($import->fresh()?->finished_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($import->path))->toBeFalse()
        ->and(AdminNotification::query()->count())->toBe(1);
});

it('leaves a finished import alone when the worker reports a failure afterwards', function (): void {
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();

    new ImportRecords($import->id)->failed();

    expect($import->fresh()?->status)->toBe('done');
});

it('refuses a spreadsheet that unpacks into far more than the limit', function (): void {
    config(['fastleo.import.max_unpacked' => 1]);
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', str_repeat('0', 3 * 1024 * 1024));
    $zip->close();

    importFile(new UploadedFile($path, 'bomb.xlsx', null, null, true));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл слишком большой после распаковки (больше 1 МБ). Разделите его на части.'])
        ->and(Article::query()->count())->toBe(0);
});

it('counts the real unpacked size, not the size written in the archive', function (): void {
    config(['fastleo.import.max_unpacked' => 1]);
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', str_repeat('0', 3 * 1024 * 1024));
    $zip->close();

    // Автор файла вписывает в заголовки малый размер: 1000 байт вместо 3 МБ.
    $bytes = (string) file_get_contents($path);
    foreach (["PK\x03\x04" => 22, "PK\x01\x02" => 24] as $signature => $offset) {
        $bytes = substr_replace($bytes, pack('V', 1000), (int) strpos($bytes, $signature) + $offset, 4);
    }
    file_put_contents($path, $bytes);

    importFile(new UploadedFile($path, 'bomb.xlsx', null, null, true));

    expect(lastImport()->status)->toBe('failed')
        ->and(lastImport()->message)->toContain('слишком большой после распаковки');
});

it('fails an import that crashed and reports the error', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    Article::saving(fn () => throw new RuntimeException('сбой'));
    Exceptions::fake();

    new ImportRecords($import->id)->handle();
    new ImportRecords($import->id)->handle(); // повторный запуск завершённого импорта ничего не делает

    expect($import->fresh()?->status)->toBe('failed');
    Exceptions::assertReported(RuntimeException::class);
});

it('writes one summary entry to the journal instead of an entry per row', function (): void {
    importFile(xlsxFile([['Заголовок'], ['Раз'], ['Два'], ['Три']]));

    $entries = Activity::query()->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->event)->toBe('import')
        ->and($entries[0]->resource)->toBe('articles')
        ->and($entries[0]->subject_id)->toBeNull()
        ->and($entries[0]->changes)->toBeStoredJson([
            'created' => ['new' => 3],
            'updated' => ['new' => 0],
            'skipped' => ['new' => 0],
            'file' => ['new' => 'import.xlsx'],
        ]);
});

it('tells the uploader in the bell how the import went, with a link to the report', function (): void {
    importFile(xlsxFile([['Заголовок'], ['Раз'], ['Два']]));

    $entry = AdminNotification::query()->sole();

    expect($entry->data['title'])->toBe('Импорт завершён')
        ->and($entry->data['body'])->toContain('создано 2')
        ->and($entry->data['url'])->toBe(route('fastleo.resources.imports.show', ['articles', lastImport()->id], false))
        ->and($entry->data['level'])->toBe('success');
});

it('tells the uploader in the bell when the import could not be made', function (): void {
    importFile(xlsxFile([['Неизвестный столбец'], ['Раз']]));

    $entry = AdminNotification::query()->sole();

    expect($entry->data['title'])->toBe('Импорт не выполнен')->and($entry->data['level'])->toBe('danger');
});

/**
 * Строки xlsx-файла на диске.
 *
 * @return list<list<mixed>>
 */
function xlsxRows(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    return $rows;
}

it('keeps every skipped row with its reason in a file for the author', function (): void {
    $rows = [['Заголовок', 'Просмотры', 'Статус']];

    foreach (range(1, Import::MAX_ERRORS + 5) as $number) {
        $rows[] = ['', $number, 'draft'];
    }

    $rows[] = ['Хорошая', 5, 'draft'];
    $rows[] = ['=1+1', -1, 'draft'];
    importFile(xlsxFile($rows, 'статьи.xlsx'));
    $import = lastImport();
    $file = xlsxRows(Storage::disk('local')->path($import->errorsPath()));

    expect($import->skipped)->toBe(Import::MAX_ERRORS + 6)
        ->and($import->errors)->toHaveCount(Import::MAX_ERRORS)
        ->and($file)->toHaveCount(Import::MAX_ERRORS + 7)
        ->and($file[0])->toBe(['Заголовок', 'Просмотры', 'Статус', 'Ошибка'])
        ->and($file[1])->toBe(['', 1, 'draft', 'Заполните поле «Заголовок».'])
        ->and(end($file))->toBe(['=1+1', -1, 'draft', 'Поле «Просмотры» должно быть не меньше 0.'])
        ->and($import->progress()['errors_file'])->toBeTrue();

    $this->get(route('fastleo.resources.imports.show', ['articles', $import->id]))->assertOk();
    $this->get(route('fastleo.resources.imports.errors', ['articles', $import->id]))
        ->assertOk()
        ->assertDownload('stati-errors.xlsx');
});

it('puts the reason into its own column when a row of the file is shorter or longer than the header', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-csv').'.csv';
    file_put_contents($path, "Заголовок;Просмотры;Статус\n;5\n;7;draft;лишнее\n");
    importFile(new UploadedFile($path, 'short.csv', null, null, true));
    $file = xlsxRows(Storage::disk('local')->path(lastImport()->errorsPath()));

    expect(lastImport()->skipped)->toBe(2)
        ->and($file[0])->toBe(['Заголовок', 'Просмотры', 'Статус', 'Ошибка'])
        ->and($file[1])->toBe(['', '5', '', 'Заполните поле «Заголовок».'])
        ->and($file[2])->toBe(['', '7', 'draft', 'Заполните поле «Заголовок».']);
});

it('removes the temporary file with skipped rows when the import crashes halfway', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок', 'Статус'], ['', 'leak-marker-9f3a'], ['Упадёт', 'draft']]));
    $import = lastImport();
    Article::saving(fn (Article $article): null => $article->title === 'Упадёт' ? throw new RuntimeException('сбой') : null);
    Exceptions::fake();
    $started = time() - 1;

    new ImportRecords($import->id)->handle();

    $leaked = collect(glob(sys_get_temp_dir().'/fastleo-skipped*') ?: [])
        ->filter(fn (string $file): bool => filemtime($file) >= $started)
        ->filter(function (string $file): bool {
            try {
                return in_array('leak-marker-9f3a', array_merge(...xlsxRows($file)), true);
            } catch (Throwable) {
                return false;
            }
        });

    expect($import->fresh()?->status)->toBe('failed')
        ->and($leaked)->toBeEmpty();
});

it('writes no file when nothing was skipped', function (): void {
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();

    expect(Storage::disk('local')->exists($import->errorsPath()))->toBeFalse()
        ->and($import->progress()['errors_file'])->toBeFalse();

    $this->get(route('fastleo.resources.imports.errors', ['articles', $import->id]))->assertNotFound();
});

it('gives the file with skipped rows to its author only', function (): void {
    importFile(xlsxFile([['Заголовок', 'Просмотры'], ['', 1]]));
    $import = lastImport();
    expect(Storage::disk('local')->exists($import->errorsPath()))->toBeTrue();

    $this->get(route('fastleo.resources.imports.errors', ['categories', $import->id]))->assertNotFound();

    $foreign = Import::query()->create(['user_id' => (string) admin()->id, 'resource' => 'articles', 'file_name' => 'x.xlsx', 'path' => 'x.xlsx', 'status' => 'done', 'skipped' => 1]);
    Storage::disk('local')->put($foreign->errorsPath(), 'x');
    $this->get(route('fastleo.resources.imports.errors', ['articles', $foreign->id]))->assertForbidden();
});

it('removes the file with skipped rows together with an old report', function (): void {
    $this->travelTo(now()->subDays(40));
    importFile(xlsxFile([['Заголовок', 'Просмотры'], ['', 1]]));
    $old = lastImport();
    $this->travelBack();
    expect(Storage::disk('local')->exists($old->errorsPath()))->toBeTrue();

    importFile(xlsxFile([['Заголовок'], ['Свежая']]));

    expect(Storage::disk('local')->exists($old->errorsPath()))->toBeFalse();
});

it('removes the file with skipped rows of an import the worker interrupted', function (): void {
    $import = Import::query()->create(['user_id' => (string) admin()->id, 'resource' => 'articles', 'file_name' => 'x.xlsx', 'path' => 'fastleo/imports/x.xlsx', 'status' => 'running']);
    Storage::disk('local')->put($import->errorsPath(), 'x');

    new ImportRecords($import->id)->failed();

    expect(Storage::disk('local')->exists($import->errorsPath()))->toBeFalse();
});
