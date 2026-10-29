<?php

declare(strict_types=1);

namespace Fastleo\Imports;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Импорт из файла: состояние и отчёт «создано / обновлено / пропущено» со списком ошибок строк.
 *
 * Записи старше `fastleo.import.keep_days` удаляются при следующем импорте.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string $resource
 * @property string $file_name
 * @property string $path
 * @property string $status pending | running | done | failed
 * @property int $processed
 * @property int $created
 * @property int $updated
 * @property int $skipped
 * @property list<array{row: int, message: string}>|null $errors
 * @property list<string>|null $unknown_columns
 * @property string|null $message
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'resource', 'file_name', 'path', 'status', 'processed', 'created', 'updated', 'skipped', 'errors', 'unknown_columns', 'message', 'finished_at'])]
#[Table(name: 'fastleo_imports')]
final class Import extends Model
{
    /** Сколько ошибок строк хранится в отчёте. */
    public const int MAX_ERRORS = 100;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

    /**
     * Импорт завершён (успешно или с ошибкой).
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    /**
     * Диск, на котором лежат файл импорта и файл с пропущенными строками.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk(config()->string('fastleo.import.disk', 'local'));
    }

    /**
     * Путь на диске к xlsx с пропущенными строками (файл появляется, только если строки пропущены).
     */
    public function errorsPath(): string
    {
        return 'fastleo/imports/'.$this->id.'-errors.xlsx';
    }

    /**
     * Запомнить ошибку строки (первые `MAX_ERRORS`).
     */
    public function addError(int $row, string $message): void
    {
        $errors = $this->errors ?? [];

        if (count($errors) < self::MAX_ERRORS) {
            $errors[] = ['row' => $row, 'message' => $message];
            $this->errors = $errors;
        }
    }

    /**
     * Состояние для страницы прогресса.
     *
     * @return array{status: string, finished: bool, processed: int, created: int, updated: int, skipped: int, errors: list<array{row: int, message: string}>, unknown_columns: list<string>, message: ?string, errors_file: bool}
     */
    public function progress(): array
    {
        // Файл есть только у завершённого импорта с пропущенными строками: на открытом импорте диск не опрашиваем.
        $errorsFile = $this->status === 'done' && $this->skipped > 0 && self::disk()->exists($this->errorsPath());

        return [
            'status' => $this->status,
            'finished' => $this->isFinished(),
            'processed' => $this->processed,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors ?? [],
            'unknown_columns' => $this->unknown_columns ?? [],
            'message' => $this->message,
            'errors_file' => $errorsFile,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'errors' => 'array',
            'unknown_columns' => 'array',
            'finished_at' => 'datetime',
        ];
    }
}
