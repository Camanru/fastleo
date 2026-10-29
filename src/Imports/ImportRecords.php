<?php

declare(strict_types=1);

namespace Fastleo\Imports;

use Fastleo\Activity\ActivityRecorder;
use Fastleo\Auth\FastleoUser;
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Fastleo\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Задача импорта файла в раздел: от имени загрузившего админа (его права по Policy),
 * с отчётом в `fastleo_imports`. Без очереди (`sync`) выполняется сразу.
 *
 * ```php
 * ImportRecords::dispatch($import->id);
 * ```
 */
#[Tries(1)]
#[Timeout(3600)]
final class ImportRecords implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $importId) {}

    /**
     * Обработать файл и записать итог.
     */
    public function handle(): void
    {
        $import = Import::query()->find($this->importId);

        if (! $import instanceof Import || $import->status !== 'pending') {
            return;
        }

        $import->update(['status' => 'running']);
        $disk = Import::disk();
        $extension = strtolower(pathinfo($import->path, PATHINFO_EXTENSION));
        // Копия во временный файл: диск может быть и не локальным (очередь на другом сервере, s3).
        $local = tempnam(sys_get_temp_dir(), 'fastleo-import');
        $importer = null;

        $guard = Fastleo::guard();
        // Задача на воркере живёт дольше одного импорта: вошедшим от имени загрузившего остаёмся только на время работы.
        $previous = $guard->hasUser() ? $guard->user() : null;

        try {
            $resource = Fastleo::resources()->find($import->resource);
            $user = $import->user_id === null ? null : $guard->getProvider()->retrieveById($import->user_id);

            if (! $resource instanceof Resource || ! $user instanceof FastleoUser || ! $user->canAccessFastleo()) {
                throw new ImportFailed(__('fastleo::imports.unavailable', locale: Fastleo::LOCALE));
            }

            $stream = $disk->readStream($import->path);

            if (! is_resource($stream) || $local === false) {
                throw new ImportFailed(__('fastleo::imports.unreadable', locale: Fastleo::LOCALE));
            }

            file_put_contents($local, $stream);
            fclose($stream);

            $guard->setUser($user);
            $recorder = resolve(ActivityRecorder::class);
            // Без очереди задача идёт внутри запроса админа: записи строк в журнал не пишем, только итог.
            $importer = new Importer($resource, $import);
            $recorder->withoutRecording(fn () => $importer->run($local, $extension));
            $this->keepSkippedRows($import, $importer->skippedFile());
            $import->status = 'done';
            $recorder->importFinished($resource, [
                'created' => $import->created,
                'updated' => $import->updated,
                'skipped' => $import->skipped,
                'file' => $import->file_name,
            ]);
        } catch (ImportFailed $exception) {
            $import->status = 'failed';
            $import->message = $exception->getMessage();
        } catch (Throwable $exception) {
            $import->status = 'failed';
            $import->message = __('fastleo::imports.crashed', locale: Fastleo::LOCALE);
            report($exception);
        } finally {
            // Временный файл пропущенных строк удаляем и когда импорт упал на полпути.
            $skipped = $importer?->skippedFile();

            if (is_string($skipped) && is_file($skipped)) {
                File::delete($skipped);
            }

            $previous instanceof Authenticatable ? $guard->setUser($previous) : $guard->forgetUser();
            $import->finished_at = now();
            $import->save();
            $disk->delete($import->path);

            if ($local !== false && is_file($local)) {
                File::delete($local);
            }
        }

        $this->notifyUploader($import);
    }

    /**
     * Воркер прервал задачу (время, память, остановка процесса): импорт не остаётся «выполняющимся» навсегда,
     * файл удаляется, администратор получает уведомление.
     */
    public function failed(?Throwable $exception = null): void
    {
        $import = Import::query()->find($this->importId);

        if (! $import instanceof Import || $import->isFinished()) {
            return;
        }

        $import->status = 'failed';
        $import->message = __('fastleo::imports.crashed', locale: Fastleo::LOCALE);
        $import->finished_at = now();
        $import->save();
        Import::disk()->delete([$import->path, $import->errorsPath()]);

        $this->notifyUploader($import);
    }

    /**
     * Положить xlsx с пропущенными строками рядом с файлом импорта (`Import::errorsPath()`).
     */
    private function keepSkippedRows(Import $import, ?string $local): void
    {
        $stream = $local === null ? false : fopen($local, 'rb');

        if (! is_resource($stream)) {
            return;
        }

        Import::disk()->writeStream($import->errorsPath(), $stream);
        fclose($stream);
    }

    /**
     * Сообщить загрузившему администратору в колокольчик, чем закончился импорт (с ссылкой на отчёт).
     */
    private function notifyUploader(Import $import): void
    {
        $user = $import->user_id === null ? null : Fastleo::guard()->getProvider()->retrieveById($import->user_id);
        $resource = Fastleo::resources()->find($import->resource);

        if (! $user instanceof Authenticatable || ! $resource instanceof Resource) {
            return;
        }

        $failed = $import->status === 'failed';
        $message = FastleoMessage::make(__($failed ? 'fastleo::notifications.import_failed' : 'fastleo::notifications.import_done', locale: Fastleo::LOCALE))
            ->body($failed ? (string) $import->message : __('fastleo::notifications.import_done_body', ['resource' => $resource::label(), 'created' => $import->created, 'updated' => $import->updated, 'skipped' => $import->skipped], Fastleo::LOCALE))
            ->url(route('fastleo.resources.imports.show', [$resource::slug(), $import->id], false))
            ->icon('upload')
            ->level($failed ? 'danger' : 'success');

        Fastleo::notify($user, $message);
    }
}
