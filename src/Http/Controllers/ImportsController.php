<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Imports\Import;
use Fastleo\Imports\ImportRecords;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Загрузка файла импорта и страница его прогресса с отчётом.
 */
final class ImportsController
{
    /**
     * `POST /fastleo/{resource}/import` с файлом `file` (xlsx или csv): сохранить файл на закрытый диск,
     * записать импорт и запустить задачу. Право на загрузку — метод `import` Policy, если он есть,
     * иначе `viewAny`; каждая строка дополнительно проверяет `create`/`update`.
     */
    public function store(Request $request, string $resource): RedirectResponse
    {
        $resource = $this->resource($resource);
        abort_unless($resource::isImportable(), 404);
        abort_unless(Authorization::allowsOwnOr('import', 'viewAny', $resource), 403);

        $request->validate(
            ['file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.max(1, config()->integer('fastleo.import.max_size', 10240))]],
            attributes: ['file' => __('fastleo::imports.file')],
        );

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $this->forgetOld();

        $disk = config()->string('fastleo.import.disk', 'local');
        $path = $file->storeAs('fastleo/imports', Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension()), $disk);
        abort_unless(is_string($path), 500);

        $user = Fastleo::guard()->user();
        $key = $user?->getAuthIdentifier();

        $import = Import::query()->create([
            'user_id' => is_scalar($key) ? (string) $key : null,
            'resource' => $resource::slug(),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
        ]);

        dispatch(new ImportRecords($import->id));

        return to_route('fastleo.resources.imports.show', [$resource::slug(), $import->id]);
    }

    /**
     * `GET /fastleo/{resource}/imports/{import}` — страница прогресса; с `Accept: application/json` — состояние.
     * Импорт виден только тому, кто его загрузил.
     */
    public function show(Request $request, string $resource, string $import): View|JsonResponse
    {
        $resource = $this->resource($resource);
        $record = $this->own($resource, $import);

        if ($request->expectsJson()) {
            return response()->json($record->progress());
        }

        return view('fastleo::imports.show', ['resource' => $resource, 'import' => $record]);
    }

    /**
     * `GET /fastleo/{resource}/imports/{import}/errors` — xlsx со всеми пропущенными строками импорта
     * (столбцы исходного файла и «Ошибка»). Только тому, кто загрузил файл; файла нет — 404.
     */
    public function errors(string $resource, string $import): StreamedResponse
    {
        $record = $this->own($this->resource($resource), $import);
        abort_unless($record->status === 'done' && Import::disk()->exists($record->errorsPath()), 404);

        return Import::disk()->download($record->errorsPath(), pathinfo($record->file_name, PATHINFO_FILENAME).'-errors.xlsx');
    }

    /**
     * Импорт этого раздела, загруженный вошедшим администратором.
     */
    private function own(Resource $resource, string $import): Import
    {
        abort_unless(preg_match('/^[0-9]{1,18}$/', $import) === 1, 404);

        $record = Import::query()->whereKey($import)->where('resource', $resource::slug())->first();
        abort_unless($record instanceof Import, 404);

        $key = Fastleo::guard()->user()?->getAuthIdentifier();
        abort_unless(is_scalar($key) && $record->user_id === (string) $key, 403);

        return $record;
    }

    private function resource(string $slug): Resource
    {
        $resource = Fastleo::resources()->find($slug);
        abort_unless($resource instanceof Resource, 404);
        Authorization::authorize('viewAny', $resource);

        return $resource;
    }

    /**
     * Удалить отчёты старше `fastleo.import.keep_days` (и забытые файлы).
     */
    private function forgetOld(): void
    {
        $disk = Import::disk();

        Import::query()
            ->where('created_at', '<', now()->subDays(max(1, config()->integer('fastleo.import.keep_days', 30))))
            ->each(function (Import $old) use ($disk): void {
                $disk->delete([$old->path, $old->errorsPath()]);
                $old->delete();
            });
    }
}
