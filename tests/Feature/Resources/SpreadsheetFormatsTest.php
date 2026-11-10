<?php

declare(strict_types=1);

use Fastleo\Exports\SpreadsheetFormats;
use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Imports\ImportRecords;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Workbench\App\Models\Article;

// Здесь настройка `fastleo.xlsx` остаётся по умолчанию (`false`): Excel в панели недоступен.

beforeEach(function (): void {
    useFixtureResources('Imports');
    Storage::fake('local');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('offers only csv by default and both formats when xlsx is turned on', function (): void {
    expect(SpreadsheetFormats::all())->toBe(['csv'])
        ->and(SpreadsheetFormats::preferred())->toBe('csv')
        ->and(SpreadsheetFormats::label())->toBe('csv');

    config(['fastleo.xlsx' => true]);

    expect(SpreadsheetFormats::all())->toBe(['xlsx', 'csv'])
        ->and(SpreadsheetFormats::preferred())->toBe('xlsx')
        ->and(SpreadsheetFormats::label())->toBe('xlsx или csv');
});

it('reads the xlsx setting as any yes or no, so that a hand-written value does not break every list with an export', function (mixed $value, array $formats): void {
    config(['fastleo.xlsx' => $value]);

    expect(SpreadsheetFormats::all())->toBe($formats);
})->with([
    'true' => [true, ['xlsx', 'csv']],
    'one' => [1, ['xlsx', 'csv']],
    'string one' => ['1', ['xlsx', 'csv']],
    'string true' => ['true', ['xlsx', 'csv']],
    'false' => [false, ['csv']],
    'zero' => [0, ['csv']],
    'string false' => ['false', ['csv']],
    'empty' => ['', ['csv']],
    'nothing' => [null, ['csv']],
]);

it('puts one export button with csv in the list instead of a menu with Excel', function (): void {
    $this->get(route('fastleo.resources.index', 'articles'))
        ->assertOk()
        ->assertSee('href="'.route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'csv']).'"', false)
        ->assertDontSee('Excel (xlsx)')
        ->assertDontSee('format=xlsx', false)
        ->assertDontSee('role="menuitem"', false);
});

it('exports csv whatever format is asked for', function (?string $format): void {
    Article::factory()->create(['title' => 'Отчёт']);

    $response = $this->get(route('fastleo.resources.export', array_filter(['resource' => 'articles', 'format' => $format])))->assertOk()->assertDownload();

    expect($response->headers->get('content-disposition'))->toMatch('/articles-\d{4}-\d{2}-\d{2}-\d{6}\.csv/')
        ->and($response->headers->get('content-type'))->toStartWith('text/csv')
        ->and(exportedRows($response, 'csv')[1][1])->toBe('Отчёт');
})->with(['xlsx' => 'xlsx', 'csv' => 'csv', 'nothing' => null, 'unknown' => 'pdf']);

it('asks for a csv file in the import window and says so', function (): void {
    $this->get(route('fastleo.resources.index', 'articles'))
        ->assertOk()
        ->assertSee('accept=".csv"', false)
        ->assertSee('CSV · до 10 МБ', false)
        ->assertSee('Файл csv до 10 МБ.', false)
        ->assertDontSee('xlsx', false)
        ->assertDontSee('XLSX', false);
});

it('does not take an xlsx file, even a real one, and takes csv', function (): void {
    Queue::fake();
    $path = tempnam(sys_get_temp_dir(), 'fastleo-xlsx').'.xlsx';
    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(new Row([new StringCell('Заголовок')]));
    $writer->close();

    $this->post(route('fastleo.resources.import', 'articles'), ['file' => new UploadedFile($path, 'book.xlsx', null, null, true)])->assertSessionHasErrors('file');

    File::delete($path);

    expect(Import::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->post(route('fastleo.resources.import', 'articles'), ['file' => UploadedFile::fake()->createWithContent('book.csv', "Заголовок\nОдин\n")])->assertSessionHasNoErrors();

    expect(Import::query()->count())->toBe(1);
});

it('names csv in the message about a file that cannot be read', function (): void {
    Queue::fake();
    $this->post(route('fastleo.resources.import', 'articles'), ['file' => UploadedFile::fake()->createWithContent('book.csv', "Заголовок\nОдин\n")]);
    $import = Import::query()->firstOrFail();
    Storage::disk('local')->delete($import->path);

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->message)->toBe('Файл не читается: нужен csv.');
});

it('does not read an xlsx file of an import that was queued before xlsx was turned off, and removes the file', function (): void {
    $import = Import::query()->create(['user_id' => (string) auth(Fastleo::GUARD)->id(), 'resource' => 'articles', 'file_name' => 'old.xlsx', 'path' => 'fastleo/imports/old.xlsx', 'status' => 'pending']);
    Storage::disk('local')->put($import->path, 'not read at all');

    new ImportRecords($import->id)->handle();

    expect($import->fresh()?->only('status', 'message'))->toBe(['status' => 'failed', 'message' => 'Файл не читается: нужен csv.'])
        ->and(Storage::disk('local')->exists($import->path))->toBeFalse();
});

it('removes the files of old reports with the xlsx file of skipped rows that earlier versions kept', function (): void {
    Queue::fake();
    $old = Import::query()->create(['user_id' => (string) auth(Fastleo::GUARD)->id(), 'resource' => 'articles', 'file_name' => 'old.csv', 'path' => 'fastleo/imports/old.csv', 'status' => 'done', 'skipped' => 1]);
    Import::query()->whereKey($old->id)->toBase()->update(['created_at' => now()->subDays(31)]);

    foreach ($old->files() as $file) {
        Storage::disk('local')->put($file, 'x');
    }

    $this->post(route('fastleo.resources.import', 'articles'), ['file' => UploadedFile::fake()->createWithContent('new.csv', "Заголовок\nОдин\n")]);

    expect(Import::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('fastleo/imports/'.$old->id.'-errors.xlsx'))->toBeFalse()
        ->and(Storage::disk('local')->exists($old->errorsPath()))->toBeFalse();
});
