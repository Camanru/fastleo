<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Imports\ImportRecords;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Tests\Fixtures\Policies\NoListProductPolicy;
use Fastleo\Tests\Fixtures\Policies\RestrictedCategoryPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;
use Workbench\App\Models\Remark;
use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

beforeEach(function (): void {
    useFixtureResources('Imports');
    Storage::fake('local');
    // xlsx в админке отключён (`fastleo.xlsx`); код чтения и записи остаётся, и здесь он проверяется включённым.
    config(['fastleo.xlsx' => true]);
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
    $writer = new XlsxWriter();
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

it('names the row of the bad one as Excel numbers it when the file has empty rows', function (): void {
    importFile(xlsxFile([
        ['Заголовок', 'Просмотры'],
        ['Первая', 1],
        ['', ''],
        ['', ''],
        ['Отрицательная', -1],
    ]));

    expect(lastImport()->errors)->toBe([['row' => 5, 'message' => 'Поле «Просмотры» должно быть не меньше 0.']])
        ->and(Article::query()->pluck('title')->all())->toBe(['Первая']);
});

it('names the row of the bad one in a csv with empty lines, too', function (): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок;Просмотры\nПервая;1\n\n\nОтрицательная;-1\n"));

    expect(lastImport()->errors)->toBe([['row' => 5, 'message' => 'Поле «Просмотры» должно быть не меньше 0.']]);
});

it('takes the first filled row for the header when the file starts with empty rows', function (): void {
    importFile(xlsxFile([[''], [''], ['Заголовок', 'Просмотры'], ['Первая', -1]]));

    expect(lastImport()->errors)->toBe([['row' => 4, 'message' => 'Поле «Просмотры» должно быть не меньше 0.']]);
});

it('counts only filled rows against the limit, not the empty ones in the file', function (): void {
    config(['fastleo.import.max_rows' => 2]);

    importFile(xlsxFile([['Заголовок'], ['Раз'], [''], [''], [''], ['Два'], ['Три']]));

    expect(lastImport()->message)->toBe('Обработаны первые 2 строк — остальные не загружены.')
        ->and(Article::query()->pluck('title')->all())->toBe(['Раз', 'Два']);
});

it('fails a file of empty rows only as a file without columns', function (): void {
    importFile(xlsxFile([[''], ['']]));

    expect(lastImport()->status)->toBe('failed');
});

it('keeps the list of unknown columns in the report short', function (): void {
    $headers = ['Заголовок', ...array_map(fn (int $number): string => 'Лишний '.$number.str_repeat('я', 200), range(1, 60))];

    importFile(xlsxFile([$headers, ['Первая', ...array_fill(0, 60, 'x')]]));

    $unknown = lastImport()->unknown_columns;

    expect($unknown)->toHaveCount(50)
        ->and(mb_strlen($unknown[0]))->toBe(100);
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

it('does not create a duplicate of a record that exists but is outside the section', function (): void {
    Category::factory()->create(['code' => 'secret', 'title' => 'Скрытая']);

    importFile(xlsxFile([['Код', 'Название'], ['secret', 'Дубль'], ['promo', 'Акции']]), 'categories');

    expect(lastImport()->only('created', 'updated'))->toBe(['created' => 1, 'updated' => 0])
        ->and(lastImport()->errors)->toBe([['row' => 2, 'message' => 'Запись с ключом secret есть, но раздел её не показывает (она в корзине или вне выборки раздела): строка не загружена, чтобы не создать дубль.']])
        ->and(Category::query()->where('code', 'secret')->count())->toBe(1);
});

it('does not create an empty record for a row that has only a key nobody has', function (): void {
    Category::factory()->create(['code' => 'news', 'title' => 'Старое', 'sort' => 1]);

    importFile(xlsxFile([['Код', 'Название'], ['news', 'Новости'], ['ghost', ''], ['promo', 'Акции']]), 'categories');

    expect(lastImport()->only('created', 'updated', 'skipped'))->toBe(['created' => 1, 'updated' => 1, 'skipped' => 1])
        ->and(lastImport()->errors)->toBe([['row' => 3, 'message' => 'Записи с ключом ghost нет.']])
        ->and(Category::query()->where('code', 'ghost')->exists())->toBeFalse();
});

it('reports a row whose key the database cannot compare instead of failing the whole import', function (): void {
    importFile(xlsxFile([['external_id', 'Название'], ['abc', 'Новости']]), 'unreadable-key');

    expect(lastImport()->only('status', 'created', 'skipped'))->toBe(['status' => 'done', 'created' => 0, 'skipped' => 1])
        ->and(lastImport()->errors)->toBe([['row' => 2, 'message' => 'Записи с ключом abc нет.']]);
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

it('writes only the first database failures of an import to the error log, not one for every row', function (): void {
    Article::saving(function (): never {
        throw new UniqueConstraintViolationException('testing', 'insert', [], new PDOException('UNIQUE constraint failed'));
    });
    Exceptions::fake();

    importFile(xlsxFile([['Заголовок'], ...array_map(fn (int $number): array => ['Строка '.$number], range(1, 8))]));

    expect(lastImport()->only('created', 'skipped'))->toBe(['created' => 0, 'skipped' => 8]);
    Exceptions::assertReportedCount(3);
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

it('finds the delimiter of a csv in its first line, with or without the BOM', function (string $delimiter, string $bom): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', $bom."Заголовок{$delimiter}Просмотры\nОтчёт{$delimiter}7\n"));

    expect(Article::query()->where('title', 'Отчёт')->value('views'))->toBe(7);
})->with([
    'semicolon' => [';', ''],
    'comma' => [',', ''],
    'tab' => ["\t", ''],
    'semicolon with BOM' => [';', "\u{FEFF}"],
    'comma with BOM' => [',', "\u{FEFF}"],
]);

it('reads a csv of one column', function (): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок\nОдин\nДва\n"));

    expect(Article::query()->orderBy('id')->pluck('title')->all())->toBe(['Один', 'Два']);
});

it('recognizes the columns whatever the case and the spaces around the header', function (): void {
    importFile(xlsxFile([[' ЗАГОЛОВОК ', ' просмотры'], ['Отчёт', 7]]));

    expect(Article::query()->where('title', 'Отчёт')->value('views'))->toBe(7);
});

it('takes the first of two columns with the same header', function (): void {
    importFile(xlsxFile([['Заголовок', 'Заголовок', 'Просмотры'], ['Первый', 'Второй', 3]]));

    expect(Article::query()->pluck('title')->all())->toBe(['Первый']);
});

it('refuses a csv with a line wider than the last column of Excel, which would take the memory of the worker', function (): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок;Просмотры\nПервая;1\n".str_repeat(';', 16_385)."\n"));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'В таблице слишком много столбцов (в строке больше 16384 ячеек). Оставьте только нужные столбцы или разделите файл.'])
        ->and(Article::query()->count())->toBe(0);
});

it('lets through a csv with a long text that has many commas in one cell', function (): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок,Просмотры\n\"".str_repeat('слово, ', 3000)."\",5\n"));

    expect(lastImport()->status)->toBe('done');
});

it('refuses a csv record that is wider than the last column of Excel and is spread over many lines of the file by quoted line breaks', function (): void {
    // Одна запись из 16 385 полей, в каждом перевод строки: в любой строке файла один разделитель, а читалка отдаёт одну строку из всех полей.
    $record = implode(',', array_fill(0, 16_385, "\"\n\""));

    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок,Просмотры\n".$record."\n"));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'В таблице слишком много столбцов (в строке больше 16384 ячеек). Оставьте только нужные столбцы или разделите файл.'])
        ->and(Article::query()->count())->toBe(0);
});

it('refuses a csv record whose line breaks sit in fields that the reader does not take for quoted, behind a stray quote', function (): void {
    // Кавычка в середине поля без кавычек — обычный символ: читалка не считает её началом поля, а запись по-прежнему продолжается на следующей строке.
    $line = 'x"y'.str_repeat(',', 9000).",\"\n\"";
    $record = implode(',', array_fill(0, 3, $line));

    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок,Просмотры\n".$record."\n"));

    expect((string) lastImport()->message)->toContain('слишком много столбцов');
});

it('checks a csv with a byte order mark and a byte that is not utf-8 by the bytes the reader sees, which does not skip the mark in Windows-1251', function (): void {
    // Для проверки это одно поле в кавычках; читалка в режиме Windows-1251 оставляет метку в данных, и кавычка уже не в начале поля.
    $content = "\xEF\xBB\xBF\"".str_repeat(',', 20_000)."\"\xFF\n";

    importFile(UploadedFile::fake()->createWithContent('import.csv', $content));

    expect((string) lastImport()->message)->toContain('слишком много столбцов');
});

it('lets through a csv with line breaks, doubled quotes and thousands of commas inside quoted fields', function (): void {
    $text = '"'.str_repeat("слово, \"\"цитата\"\",\n", 9000).'"';

    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок,Просмотры\n".$text.",5\nВторая,6\n"));

    // Защита пропустила файл; первая строка не прошла проверку длины заголовка, вторая сохранена.
    expect(lastImport()->status)->toBe('done')
        ->and(Article::query()->pluck('title')->all())->toBe(['Вторая']);
});

it('does not split a one-column csv at a semicolon in the text', function (): void {
    importFile(UploadedFile::fake()->createWithContent('import.csv', "Заголовок\nОдин; два\n"));

    expect(Article::query()->pluck('title')->all())->toBe(['Один; два']);
});

it('reads only the first sheet of a workbook', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-xlsx').'.xlsx';
    $writer = new XlsxWriter();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Заголовок']));
    $writer->addRow(Row::fromValues(['С первого листа']));
    $writer->addNewSheetAndMakeItCurrent();
    $writer->addRow(Row::fromValues(['Заголовок']));
    $writer->addRow(Row::fromValues(['Со второго листа']));
    $writer->close();

    importFile(new UploadedFile($path, 'two-sheets.xlsx', null, null, true));

    expect(Article::query()->pluck('title')->all())->toBe(['С первого листа']);
});

it('saves the progress while a long file is read, not only at the end', function (): void {
    $progressWrites = function (int $rows): int {
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            $writes += str_starts_with($query->sql, 'update') && str_contains($query->sql, 'imports') && str_contains($query->sql, 'processed') ? 1 : 0;
        });
        importFile(xlsxFile([['Заголовок'], ...array_map(fn (int $number): array => ['Статья '.$number], range(1, $rows))]));

        return $writes;
    };

    $short = $progressWrites(5);
    $long = $progressWrites(250);

    expect($long)->toBeGreaterThanOrEqual($short + 2);
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
    $zip = new ZipArchive();
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
    $zip = new ZipArchive();
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

/**
 * Таблица xlsx с лишним файлом внутри: после распаковки в ней ровно столько байт, сколько указано.
 */
function xlsxUnpackingTo(int $bytes): UploadedFile
{
    $path = xlsxFile([['Заголовок'], ['Из архива']])->getPathname();
    $zip = new ZipArchive();
    $zip->open($path);
    $unpacked = 0;

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $unpacked += (int) $zip->statIndex($index)['size'];
    }

    $zip->addFromString('padding.bin', str_repeat('0', $bytes - $unpacked));
    $zip->close();

    return new UploadedFile($path, 'padded.xlsx', null, null, true);
}

it('takes a spreadsheet that unpacks into exactly the limit and refuses one that is a byte more', function (): void {
    config(['fastleo.import.max_unpacked' => 1]);

    importFile(xlsxUnpackingTo(1024 * 1024));

    expect(lastImport()->status)->toBe('done')
        ->and(Article::query()->pluck('title')->all())->toBe(['Из архива']);

    importFile(xlsxUnpackingTo(1024 * 1024 + 1));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл слишком большой после распаковки (больше 1 МБ). Разделите его на части.'])
        ->and(Article::query()->count())->toBe(1);
});

it('adds up the unpacked size of all files of the archive and allows a megabyte at least', function (): void {
    config(['fastleo.import.max_unpacked' => 0]);
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/one.xml', str_repeat('0', 600 * 1024));
    $zip->addFromString('xl/two.xml', str_repeat('0', 600 * 1024));
    $zip->close();

    importFile(new UploadedFile($path, 'two-files.xlsx', null, null, true));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл слишком большой после распаковки (больше 1 МБ). Разделите его на части.']);
});

it('runs the rows of a worker as the uploader in the guard and the language of the form', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    $seen = [];
    Article::creating(function () use (&$seen): void {
        $seen = ['user' => auth()->id(), 'locale' => app()->getLocale()];
    });
    // Воркер очереди: ни guard админки, ни её языка по умолчанию нет.
    Auth::shouldUse('web');
    app()->setLocale('en');

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->status)->toBe('done')
        ->and($seen)->toBe(['user' => (int) $import->user_id, 'locale' => 'ru'])
        ->and(Auth::getDefaultDriver())->toBe('web')
        ->and(app()->getLocale())->toBe('en');
});

/**
 * Архив xlsx с одним листом, написанным вручную: читалка дойдёт до него, только если защита его пропустит.
 */
function xlsxWithSheet(string $sheet, string $entry = 'xl/worksheets/sheet1.xml'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($entry, $sheet);
    $zip->close();

    return new UploadedFile($path, 'sheet.xlsx', null, null, true);
}

const SHEET_NAMESPACE = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';

it('refuses a sheet with a row of thousands of empty cells that would keep the worker busy for hours', function (): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1">'.str_repeat('<c/>', 1001).'</row></sheetData></worksheet>'));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'В таблице слишком много столбцов (в строке больше 1000 ячеек). Оставьте только нужные столбцы или разделите файл.'])
        ->and(Article::query()->count())->toBe(0);
});

it('counts the cells of every row separately and reads a row of exactly the limit', function (): void {
    $row = fn (int $number): string => '<row r="'.$number.'">'.str_repeat('<c/>', 1000).'</row>';

    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData>'.$row(1).$row(2).'</sheetData></worksheet>'));

    // Защита пропустила: дальше таблицу не открывает читалка (в архиве нет книги), а не защита.
    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('refuses a row that claims a span of hundreds of millions of columns', function (): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1" spans="1:900000000"><c r="A1"/></row></sheetData></worksheet>'));

    expect(lastImport()->message)->toContain('слишком много столбцов');
});

it('reads the span the way the sheet reader does, so no spelling of a huge number gets past the guard', function (string $spans): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1" spans="'.$spans.'"><c r="A1"/></row></sheetData></worksheet>'));

    expect(lastImport()->message)->toContain('слишком много столбцов');
})->with([
    'an exponent' => '1:9e8',
    'a plus sign' => '1:+900000000',
    'a space after the colon' => '1: 900000000',
    'a space at the end' => '1:900000000 ',
]);

it('checks every part of the archive, whatever its extension: the sheet is taken by the path from the workbook relations', function (): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1" spans="1:900000000"><c r="A1"/></row></sheetData></worksheet>', 'xl/worksheets/sheet1.dat'));

    expect(lastImport()->message)->toContain('слишком много столбцов');
});

it('refuses a row far beyond the last row of Excel: the reader would hand over every empty row in between', function (): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1"><c r="A1"/></row><row r="9000000000000"><c r="A1"/></row></sheetData></worksheet>'));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'В таблице больше строк, чем вмещает Excel (1 048 576), или строка стоит дальше последней. Проверьте файл.']);
});

it('refuses more rows than Excel holds even when the rows carry no numbers, and allows the last row itself', function (): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData>'.str_repeat('<row/>', 1_048_577).'</sheetData></worksheet>'));

    expect(lastImport()->message)->toContain('больше строк, чем вмещает Excel');

    // Защита пропустила: дальше таблицу не открывает читалка (в архиве нет книги), а не защита.
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1048576"><c r="A1"/></row></sheetData></worksheet>'));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('refuses a cell far beyond the last column of Excel and allows the last column itself', function (string $address, bool $refused): void {
    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1" spans="1:16384"><c r="'.$address.'"/></row></sheetData></worksheet>'));

    expect(str_contains((string) lastImport()->message, 'слишком много столбцов'))->toBe($refused);
})->with([
    'the last column' => ['XFD1', false],
    'one past the last column' => ['XFE1', true],
    'four letters' => ['AAAA1', true],
    'a column with no row number' => ['B', false],
    'an address that is not a cell address' => ['1', false],
]);

it('looks for rows in any xml file and by the local name of the element', function (): void {
    importFile(xlsxWithSheet('<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData><x:row>'.str_repeat('<x:c/>', 1001).'</x:row></x:sheetData></x:worksheet>', 'xl/anywhere/else.xml'));

    expect(lastImport()->message)->toContain('слишком много столбцов');
});

it('does not take the entries of the calculation chain for the cells of a row, however many there are', function (): void {
    importFile(xlsxWithSheet('<calcChain '.SHEET_NAMESPACE.'>'.str_repeat('<c r="A1" i="1"/>', 50_000).'</calcChain>', 'xl/calcChain.xml'));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

/**
 * Архив xlsx с настоящей книгой и одним листом под любым именем: читалка откроет лист, если защита его пропустит.
 */
function xlsxWithWorkbook(string $sheet, string $entry = 'worksheets/sheet1.xml'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<workbook '.SHEET_NAMESPACE.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="'.$entry.'"/></Relationships>');
    $zip->addFromString('xl/'.$entry, $sheet);
    $zip->close();

    return new UploadedFile($path, 'book.xlsx', null, null, true);
}

it('checks a sheet whose name has a hash in it: the archive opens such a part and the sheet reader takes it by the path from the relations', function (): void {
    importFile(xlsxWithWorkbook('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1" spans="1:900000000"><c r="A1"/></row></sheetData></worksheet>', 'worksheets/s#1.xml'));

    expect(lastImport()->message)->toContain('слишком много столбцов');
});

it('imports a sheet whose name has a hash in it', function (): void {
    $cell = fn (string $address, string $text): string => '<c r="'.$address.'" t="inlineStr"><is><t>'.$text.'</t></is></c>';

    importFile(xlsxWithWorkbook('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1">'.$cell('A1', 'Заголовок').'</row><row r="2">'.$cell('A2', 'Из листа с решёткой').'</row></sheetData></worksheet>', 'worksheets/s#1.xml'));

    expect(lastImport()->status)->toBe('done')
        ->and(Article::query()->pluck('title')->all())->toBe(['Из листа с решёткой']);
});

it('refuses cells outside of a row in numbers that keep the reader busy: each one copies the cells of the row so far', function (): void {
    $sheet = fn (string $address): string => '<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1"><c r="A1"/></row>'.str_repeat('<c r="'.$address.'"/>', 125_000).'<row r="2"><c r="A2"/></row></sheetData></worksheet>';

    importFile(xlsxWithWorkbook($sheet('XFD1')));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Таблица слишком тяжёлая для чтения: в ней слишком много ячеек, широких строк или лишних столбцов. Оставьте только нужные данные или разделите файл.']);

    // Те же ячейки в первом столбце копируют один элемент: это не нагрузка.
    importFile(xlsxWithWorkbook($sheet('A1')));

    expect(lastImport()->message)->not->toContain('слишком тяжёлая');
});

it('refuses many rows that claim the widest span with a single cell: the reader fills the whole width in every row', function (): void {
    $sheet = fn (int $rows): string => '<worksheet '.SHEET_NAMESPACE.'><sheetData>'.str_repeat('<row spans="1:16384"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>', $rows).'</sheetData></worksheet>';

    importFile(xlsxWithWorkbook($sheet(25_000)));

    expect(lastImport()->message)->toContain('слишком тяжёлая');

    importFile(xlsxWithWorkbook($sheet(1000)));

    expect(lastImport()->message)->not->toContain('слишком тяжёлая');
});

it('counts the sheet dimension as the width of every row, as the reader does, and refuses a dimension beyond the last column', function (): void {
    $sheet = fn (string $reference, int $rows): string => '<worksheet '.SHEET_NAMESPACE.'><dimension ref="'.$reference.'"/><sheetData>'.str_repeat('<row><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>', $rows).'</sheetData></worksheet>';

    importFile(xlsxWithWorkbook($sheet('A1:XFD25000', 25_000)));

    expect(lastImport()->message)->toContain('слишком тяжёлая');

    importFile(xlsxWithWorkbook($sheet('A1:ZZZZZZZZ1', 1)));

    expect(lastImport()->message)->toContain('слишком много столбцов');

    importFile(xlsxWithWorkbook($sheet('A1:C3', 3)));

    expect(lastImport()->message)->not->toContain('слишком');

    importFile(xlsxWithWorkbook($sheet('A1', 3)));

    expect(lastImport()->message)->not->toContain('слишком');
});

it('counts a cell without an address the way the sheet reader does, one column to the right of the previous one, even outside any row', function (): void {
    $cells = fn (int $count): string => '<worksheet '.SHEET_NAMESPACE.'><sheetData>'.str_repeat('<c t="inlineStr"><is><t>a</t></is></c>', $count).'</sheetData></worksheet>';

    // Без адреса читалка кладёт каждую следующую ячейку в новый столбец, копируя весь массив: время растёт как квадрат числа ячеек.
    importFile(xlsxWithWorkbook($cells(16_385)));

    expect(lastImport()->message)->toContain('слишком много столбцов');

    // Защита пропустила: дальше таблицу не открывает читалка (в архиве нет книги), а не защита.
    importFile(xlsxWithSheet($cells(16_384)));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('starts counting the cells without an address from the first column again in every row', function (): void {
    $row = fn (int $number): string => '<row r="'.$number.'">'.str_repeat('<c/>', 1000).'</row>';

    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData>'.implode('', array_map($row, range(1, 20))).'</sheetData></worksheet>'));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('reads the dimension of the sheet the way the sheet reader does: capital letters only, and a later one that is not a range changes nothing', function (): void {
    $sheet = fn (string $dimensions, int $rows): string => '<worksheet '.SHEET_NAMESPACE.'>'.$dimensions.'<sheetData>'.str_repeat('<row spans="1:1"><c r="A1" t="inlineStr"><is><t>x</t></is></c></row>', $rows).'</sheetData></worksheet>';

    // Читалка видит в «a1:b2 A1:XFE9» только второй диапазон (буквы в нижнем регистре она пропускает), а не первый.
    importFile(xlsxWithWorkbook($sheet('<dimension ref="a1:b2 A1:XFE9"/>', 1)));

    expect(lastImport()->message)->toContain('слишком много столбцов');

    // Второй `<dimension>` без диапазона читалка пропускает: ширина остаётся от первого.
    importFile(xlsxWithWorkbook($sheet('<dimension ref="A1:XFD1"/><dimension ref="A1"/>', 25_000)));

    expect(lastImport()->message)->toContain('слишком тяжёлая');
});

/**
 * Архив xlsx без книги, в оглавлении которого одно имя встречается много раз (`ZipArchive` так не умеет: он заменяет запись,
 * поэтому архив собирается вручную). `zip://` отдаёт первую запись с этим именем.
 */
function zipWithDuplicateNames(string $name, string $first, int $duplicates): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $entries = [$first, ...array_fill(0, $duplicates, '<worksheet/>')];
    $archive = '';
    $directory = '';

    foreach ($entries as $data) {
        $header = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0x21, crc32($data), strlen($data), strlen($data), strlen($name), 0).$name;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0x21, crc32($data), strlen($data), strlen($data), strlen($name), 0, 0, 0, 0, 0, strlen($archive)).$name;
        $archive .= $header.$data;
    }

    file_put_contents($path, $archive.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($directory), strlen($archive), 0));

    return new UploadedFile($path, 'duplicates.xlsx', null, null, true);
}

it('reads each name of the archive once, however many entries repeat it: the archive opens the first one, so repeats only make the guard read it again', function (): void {
    $sheet = '<worksheet '.SHEET_NAMESPACE.'><sheetData>'.str_repeat('<c r="A1"/>', 30_000).'</sheetData></worksheet>';

    // 30 000 ячеек стоят около 6 · 10⁶, четыреста чтений одной записи — 2,4 · 10⁹, больше предела.
    importFile(zipWithDuplicateNames('xl/worksheets/sheet1.xml', $sheet, 400));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('refuses a single cell or a single shared string split into a huge number of text runs: the reader goes through them in a time that grows as their square', function (): void {
    $runs = fn (int $count): string => str_repeat('<r><t/></r>', $count);

    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1"><c r="A1" t="inlineStr"><is>'.$runs(70_000).'</is></c></row></sheetData></worksheet>'));

    expect(lastImport()->message)->toContain('слишком тяжёлая');

    importFile(xlsxWithSheet('<sst '.SHEET_NAMESPACE.'><si>'.$runs(70_000).'</si></sst>', 'xl/sharedStrings.xml'));

    expect(lastImport()->message)->toContain('слишком тяжёлая');
});

/**
 * Архив xlsx с настоящей книгой, перед которой лежит лишняя часть: читалка её не открывает, а защита читает все части.
 */
function xlsxWithDecoy(string $decoy, string $sheet): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('customXml/item1.xml', $decoy);
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<workbook '.SHEET_NAMESPACE.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    return new UploadedFile($path, 'book.xlsx', null, null, true);
}

it('does not let a negative span of one part cancel the estimate of the work for the sheet that follows it', function (): void {
    $heavy = '<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1"><c r="A1" t="inlineStr"><is>'.str_repeat('<r><t/></r>', 70_000).'</is></c></row></sheetData></worksheet>';

    importFile(xlsxWithDecoy('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1" spans="1:-2000000000000000000"><c r="A1"/></row></sheetData></worksheet>', $heavy));

    expect(lastImport()->message)->toContain('слишком тяжёлая');
});

it('refuses an archive of thousands of entries before reading any of them: each entry costs the guard a new opening of the archive', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach (range(1, 1001) as $number) {
        $zip->addFromString('docProps/part'.$number.'.xml', '<a/>');
    }

    $zip->close();

    importFile(new UploadedFile($path, 'many.xlsx', null, null, true));

    expect(lastImport()->message)->toContain('слишком тяжёлая');
});

it('refuses a workbook of hundreds of sheets or relations: the reader goes through all the relations again for every sheet', function (string $book): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook '.SHEET_NAMESPACE.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.($book === 'sheets' ? str_repeat('<sheet name="S" sheetId="1" r:id="rId1"/>', 257) : '<sheet name="S" sheetId="1" r:id="rId1"/>').'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.str_repeat('<Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/>', $book === 'relations' ? 1001 : 1).'</Relationships>');
    $zip->close();

    importFile(new UploadedFile($path, 'book.xlsx', null, null, true));

    expect(lastImport()->message)->toContain('слишком тяжёлая');
})->with(['sheets', 'relations']);

it('does not count the relations of a sheet: a sheet with thousands of hyperlinks is an ordinary table', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.str_repeat('<Relationship Id="rId1" Type="x" Target="https://example.com" TargetMode="External"/>', 5000).'</Relationships>');
    $zip->close();

    importFile(new UploadedFile($path, 'links.xlsx', null, null, true));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('lets through cells and shared strings with as many text runs as a real document has', function (): void {
    $runs = str_repeat('<r><t>слово </t></r>', 2000);

    importFile(xlsxWithSheet('<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1"><c r="A1" t="inlineStr"><is>'.$runs.'</is></c></row></sheetData></worksheet>'));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');

    importFile(xlsxWithSheet('<sst '.SHEET_NAMESPACE.'>'.str_repeat('<si>'.$runs.'</si>', 20).'</sst>', 'xl/sharedStrings.xml'));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('stops an import whose file takes too long to read, and keeps the rows written before that', function (): void {
    config(['fastleo.import.max_read_seconds' => 0.001]);
    $cell = fn (string $address, string $text): string => '<c r="'.$address.'" t="inlineStr"><is><t>'.$text.'</t></is></c>';
    $rows = '<row r="1">'.$cell('A1', 'Заголовок').'</row>';

    foreach (range(2, 400) as $number) {
        $rows .= '<row r="'.$number.'">'.$cell('A'.$number, 'Статья '.$number).'</row>';
    }

    importFile(xlsxWithWorkbook('<worksheet '.SHEET_NAMESPACE.'><sheetData>'.$rows.'</sheetData></worksheet>'));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл читается слишком долго (больше 0.001 с) — импорт остановлен, уже записанные строки остались. Оставьте только нужные данные или разделите файл.'])
        ->and(Article::query()->count())->toBeLessThan(399);
});

it('lets through a wide table of thousands of rows that stays within the budget', function (): void {
    $row = fn (int $number): string => '<row r="'.$number.'">'.implode('', array_map(fn (int $column): string => '<c r="'.chr(64 + intdiv($column, 26)).chr(65 + $column % 26).$number.'" t="inlineStr"><is><t>x</t></is></c>', range(26, 125))).'</row>';

    importFile(xlsxWithWorkbook('<worksheet '.SHEET_NAMESPACE.'><sheetData>'.implode('', array_map($row, range(1, 2000))).'</sheetData></worksheet>'));

    expect(lastImport()->message)->not->toContain('слишком');
});

it('does not fail on an empty row or an entry the archive cannot give', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet '.SHEET_NAMESPACE.'><sheetData><row r="1"/><row r="2"><c r="A2"/></row></sheetData></worksheet>');
    $zip->addFromString('xl/worksheets/broken.xml', '<worksheet><sheetData><row><c></worksheet>');
    $zip->addFromString('xl/secret.xml', '<a/>');
    $zip->setEncryptionName('xl/secret.xml', ZipArchive::EM_AES_256, 'password');
    $zip->close();

    importFile(new UploadedFile($path, 'odd.xlsx', null, null, true));

    expect(lastImport()->message)->toBe('Файл не читается: нужен xlsx или csv.');
});

it('fails an import that crashed and reports the error', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    Article::saving(fn (): never => throw new RuntimeException('сбой'));
    Exceptions::fake();

    new ImportRecords($import->id)->handle();
    new ImportRecords($import->id)->handle(); // повторный запуск завершённого импорта ничего не делает

    expect($import->fresh()?->status)->toBe('failed');
    Exceptions::assertReported(RuntimeException::class);
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
 * Строки csv-файла на диске (разделитель «;», как у выгрузки).
 *
 * @return list<list<mixed>>
 */
function csvRows(string $path): array
{
    $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: ';'));
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

it('stops writing the file of skipped rows when it grows past a limit of cells, and says so in its last row', function (): void {
    // Заголовок в 16 тысяч столбцов: каждая пропущенная строка занимает в файле тысячи ячеек, а строк до 50 000.
    $header = 'Заголовок;Просмотры;'.implode(';', array_map(fn (int $number): string => 'c'.$number, range(1, 16_000)));
    $path = tempnam(sys_get_temp_dir(), 'fastleo-csv').'.csv';
    file_put_contents($path, $header."\n".str_repeat(";-1\n", 400));
    importFile(new UploadedFile($path, 'wide.csv', null, null, true));
    $import = lastImport();
    // Файл читается строками, а не читалкой таблиц: пять миллионов ячеек она разбирала бы несколько секунд.
    $lines = file(Storage::disk('local')->path($import->errorsPath()), FILE_IGNORE_NEW_LINES) ?: [];

    expect($import->skipped)->toBe(400)
        ->and(count($lines))->toBeLessThan(400)
        ->and(end($lines))->toContain('Файл неполный: дальше пропущенные строки не записаны (слишком много ячеек). Исправьте эти строки и загрузите файл снова.');
});

it('keeps every skipped row with its reason in a file for the author', function (): void {
    $rows = [['Заголовок', 'Просмотры', 'Статус']];

    foreach (range(1, Import::MAX_ERRORS + 5) as $number) {
        $rows[] = ['', $number, 'draft'];
    }

    $rows[] = ['Хорошая', 5, 'draft'];
    $rows[] = ['=1+1', -1, 'draft'];
    importFile(xlsxFile($rows, 'статьи.xlsx'));
    $import = lastImport();
    $file = csvRows(Storage::disk('local')->path($import->errorsPath()));

    // Файл csv: числа читаются строками, а текст, похожий на формулу, начинается с «'» (при загрузке файла обратно он снимается).
    expect($import->skipped)->toBe(Import::MAX_ERRORS + 6)
        ->and($import->errors)->toHaveCount(Import::MAX_ERRORS)
        ->and($file)->toHaveCount(Import::MAX_ERRORS + 7)
        ->and($file[0])->toBe(['Заголовок', 'Просмотры', 'Статус', 'Ошибка'])
        ->and($file[1])->toBe(['', '1', 'draft', 'Заполните поле «Заголовок».'])
        ->and(end($file))->toBe(["'=1+1", '-1', 'draft', 'Поле «Просмотры» должно быть не меньше 0.'])
        ->and($import->progress()['errors_file'])->toBeTrue();

    $this->get(route('fastleo.resources.imports.show', ['articles', $import->id]))->assertOk();
    $this->get(route('fastleo.resources.imports.errors', ['articles', $import->id]))
        ->assertOk()
        ->assertDownload('stati-errors.csv');
});

it('puts the reason into its own column when a row of the file is shorter or longer than the header', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-csv').'.csv';
    file_put_contents($path, "Заголовок;Просмотры;Статус\n;5\n;7;draft;лишнее\n");
    importFile(new UploadedFile($path, 'short.csv', null, null, true));
    $file = csvRows(Storage::disk('local')->path(lastImport()->errorsPath()));

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
                return in_array('leak-marker-9f3a', array_merge(...csvRows($file)), true);
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

it('fails an import whose uploaded file has disappeared before the worker started', function (): void {
    Queue::fake();
    importFile(xlsxFile([['Заголовок'], ['Одна']]));
    $import = lastImport();
    Storage::disk('local')->delete($import->path);

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл не читается: нужен xlsx или csv.'])
        ->and(Article::query()->count())->toBe(0);
});

it('fails a file without a single row', function (): void {
    importFile(UploadedFile::fake()->createWithContent('empty.csv', ''));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'В файле нет ни одного известного столбца. Первая строка должна содержать подписи или имена полей, как в файле экспорта.']);
});

it('fails an archive that is not a spreadsheet', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'это не таблица');
    $zip->close();

    importFile(new UploadedFile($path, 'fake.xlsx', null, null, true));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл не читается: нужен xlsx или csv.'])
        ->and(Article::query()->count())->toBe(0);
});

it('fails an archive with an entry that cannot be unpacked', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', '<sst/>');
    $zip->setEncryptionName('xl/sharedStrings.xml', ZipArchive::EM_AES_256, 'secret');
    $zip->close();

    importFile(new UploadedFile($path, 'locked.xlsx', null, null, true));

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл не читается: нужен xlsx или csv.']);
});

it('skips a row whose key is a number too big for an integer', function (): void {
    importFile(xlsxFile([['id', 'Заголовок'], [1.0e19, 'Призрак']]));

    expect(lastImport()->only('created', 'updated', 'skipped'))->toBe(['created' => 0, 'updated' => 0, 'skipped' => 1])
        ->and(Article::query()->count())->toBe(0);
});

it('fails an archive with damaged data whether or not the server reports PHP warnings', function (int $level): void {
    $path = tempnam(sys_get_temp_dir(), 'fastleo-zip').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', str_repeat('hello world ', 5000));
    $zip->close();

    // Сжатые данные записи (после локального заголовка) заменены мусором.
    $bytes = (string) file_get_contents($path);
    file_put_contents($path, substr_replace($bytes, str_repeat("\xFF", 40), 30 + strlen('xl/sharedStrings.xml') + 20, 40));
    $previous = error_reporting($level);

    try {
        importFile(new UploadedFile($path, 'damaged.xlsx', null, null, true));
    } finally {
        error_reporting($previous);
    }

    expect(lastImport()->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл не читается: нужен xlsx или csv.']);
})->with([
    'warnings reported' => [E_ALL],
    'warnings hidden' => [E_ALL & ~E_WARNING],
]);

it('keeps the object of a record that the export leaves empty when the cell stays empty', function (): void {
    useFixtureResources('Morph');
    $stored = Remark::query()->forceCreate(['body' => 'Старая', 'subject_type' => 'App\\Models\\Gone', 'subject_id' => 3]);

    importFile(xlsxFile([['id', 'Заметка', 'О чём'], [$stored->id, 'Новая', '']]), 'remarks');

    expect($stored->refresh()->only('body', 'subject_type', 'subject_id'))->toBe(['body' => 'Новая', 'subject_type' => 'App\\Models\\Gone', 'subject_id' => 3]);
});

it('does not import an object of a section the administrator may not list or one named by its class', function (): void {
    useFixtureResources('Morph');
    Gate::policy(Product::class, NoListProductPolicy::class);
    $article = Article::factory()->create();
    $product = Product::factory()->create(['is_visible' => true]);

    importFile(xlsxFile([
        ['id', 'Заметка', 'О чём'],
        ['', 'Закрытый раздел', 'products:'.$product->id],
        ['', 'Класс модели', Article::class.':'.$article->id],
    ]), 'remarks');

    expect(lastImport()->only('created', 'skipped'))->toBe(['created' => 0, 'skipped' => 2])
        ->and(Remark::query()->count())->toBe(0);
});

it('imports the object of a record as a section and a key and checks it like the form', function (): void {
    useFixtureResources('Morph');
    $article = Article::factory()->create();
    $shown = Product::factory()->create(['is_visible' => true]);
    $hidden = Product::factory()->create(['is_visible' => false]);
    $stored = Remark::query()->forceCreate(['body' => 'Старая', 'subject_type' => 'App\\Models\\Gone', 'subject_id' => 3]);

    importFile(xlsxFile([
        ['id', 'Заметка', 'О чём'],
        ['', 'Статья', 'articles:'.$article->id],
        ['', 'Товар', 'products:'.$shown->id],
        ['', 'Скрытый', 'products:'.$hidden->id],
        ['', 'Чужой раздел', 'remarks:1'],
        ['', 'Без ключа', 'articles'],
        ['', 'Пусто', ''],
        [$stored->id, 'Обновлена', 'articles:'.$article->id],
    ]), 'remarks');

    $remarks = Remark::query()->orderBy('id')->get()->map(fn (Remark $remark): array => [$remark->body, $remark->subject_type, $remark->subject_id])->all();

    expect(lastImport()->only('created', 'updated', 'skipped'))->toBe(['created' => 3, 'updated' => 1, 'skipped' => 3])
        ->and($remarks)->toBe([
            ['Обновлена', Article::class, $article->id],
            ['Статья', Article::class, $article->id],
            ['Товар', Product::class, $shown->id],
            ['Пусто', null, null],
        ]);
});
