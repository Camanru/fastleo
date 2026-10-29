<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Files\FileName;
use Fastleo\Files\FileRejected;
use Fastleo\Files\FileStore;
use Fastleo\Files\FileTypes;
use Fastleo\Files\PathGuard;
use Fastleo\Files\UploadLimits;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Файловый менеджер: папки и файлы внутри корня загрузок на диске Storage.
 *
 * Открыт всем, кто может войти в админку; раздел «Файлы» целиком и удаление можно закрыть Gate
 * `manageFastleoFiles` (`FileStore::canManage()`), окно выбора, загрузка и обрезка остаются у всех. Изменяющие действия — POST/DELETE с CSRF,
 * ответы — JSON (страница обновляет сетку сама). Каждый путь из запроса проходит
 * `PathGuard`; загрузка — белый список расширений, проверка и пересохранение картинок.
 */
final class FilesController
{
    /** Наибольшее число путей в одном удалении. */
    private const int MAX_DELETE = 500;

    /**
     * `GET /fastleo/files?folder=uploads/doctors` — страница менеджера.
     *
     * В окне выбора (`picker=field`, его открывают поля и редактор) — без меню: `accept=images` показывает только
     * картинки, `multiple=1` разрешает выбрать несколько, `current=путь` открывает папку
     * текущего файла поля и подсвечивает его.
     */
    public function index(Request $request): View
    {
        $root = PathGuard::root();
        $current = PathGuard::entry($request->query('current'));
        $folder = PathGuard::inside($request->query('folder'))
            ?? ($current !== null ? dirname($current) : $root);

        if ($folder !== $root && ! FileStore::disk()->directoryExists($folder)) {
            $folder = $root;
        }

        $picker = $request->query('picker') === 'field' ? 'field' : null;
        abort_if($picker === null && ! FileStore::canManage(), 403);

        $imagesOnly = $picker !== null && $request->query('accept') === 'images';
        $listing = FileStore::listing($folder);

        if ($imagesOnly) {
            $listing['files'] = array_values(array_filter($listing['files'], static fn (array $file): bool => $file['image']));
        }

        return view('fastleo::files.index', [
            'folder' => $folder,
            'breadcrumbs' => $this->breadcrumbs($root, $folder),
            'listing' => $listing,
            'picker' => $picker,
            'multiple' => $picker === 'field' && $request->query('multiple') === '1',
            'current' => $current,
            'query' => array_filter([
                'picker' => $picker,
                'accept' => $imagesOnly ? 'images' : null,
                'multiple' => $picker === 'field' && $request->query('multiple') === '1' ? '1' : null,
            ]),
            'limits' => ['file' => UploadLimits::perFile(), 'total' => UploadLimits::total()],
        ]);
    }

    /**
     * `POST /fastleo/files/upload` с `folder` и `files[]` → `{"files": [{path, url, name, image}], "errors": ["…"]}`.
     *
     * Папка создаётся, если её ещё нет (папка поля `directory()`). Отклонённые файлы
     * не мешают остальным: их причины — в `errors`.
     *
     * @throws ValidationException
     */
    public function upload(Request $request): JsonResponse
    {
        /** @var mixed $files вложенные массивы `files[0][]` тоже возможны — их отвергает проверка */
        $files = $request->file('files');
        $attributes = [];

        foreach (is_array($files) ? $files : [] as $index => $file) {
            if ($file instanceof UploadedFile) {
                $attributes['files.'.$index] = '«'.$file->getClientOriginalName().'»';
            }
        }

        $maxSize = UploadLimits::kilobytes();

        Validator::make($request->all(), [
            'folder' => ['required', 'string'],
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['required', 'file', 'extensions:'.implode(',', FileTypes::allowed()), ...($maxSize !== null ? ['max:'.$maxSize] : [])],
        ], ValidationMessages::all(), $attributes)->validate();

        $folder = PathGuard::inside($request->input('folder'));

        if ($folder === null || ! $this->newFoldersAreNamedSafely($folder)) {
            throw ValidationException::withMessages(['folder' => __('fastleo::files.folder_invalid')]);
        }

        $stored = [];
        $errors = [];

        foreach (is_array($files) ? $files : [] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $path = FileStore::store($file, $folder);
                $stored[] = ['path' => $path, 'url' => FileStore::url($path), 'name' => basename($path), 'image' => FileTypes::isImage($path)];
            } catch (FileRejected $rejected) {
                $errors[] = $rejected->getMessage();
            }
        }

        return response()->json(['files' => $stored, 'errors' => $errors]);
    }

    /**
     * `POST /fastleo/files/folder` с `folder` (родитель) и `name` → `{"path": "uploads/novaya"}`.
     *
     * @throws ValidationException
     */
    public function folder(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'folder' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
        ], ValidationMessages::all(), ['name' => __('fastleo::files.folder_placeholder')])->validate();

        $parent = PathGuard::inside($request->input('folder'));

        if ($parent === null || ($parent !== PathGuard::root() && ! FileStore::disk()->directoryExists($parent))) {
            throw ValidationException::withMessages(['folder' => __('fastleo::files.folder_invalid')]);
        }

        try {
            $path = FileStore::makeFolder($parent, $request->string('name')->toString());
        } catch (FileRejected $rejected) {
            throw ValidationException::withMessages(['name' => $rejected->getMessage()]);
        }

        return response()->json(['path' => $path]);
    }

    /**
     * Папки, которых ещё нет, создаются по тем же правилам имени, что и в менеджере (латиница, цифры, дефис):
     * `?`, `#` и пробелы в имени папки ломают ссылки на файлы в ней.
     */
    private function newFoldersAreNamedSafely(string $folder): bool
    {
        $disk = FileStore::disk();

        while ($folder !== '' && $folder !== PathGuard::root() && ! $disk->directoryExists($folder)) {
            $name = basename($folder);

            if (FileName::folder($name) !== $name) {
                return false;
            }

            $folder = dirname($folder) === '.' ? '' : dirname($folder);
        }

        return true;
    }

    /**
     * `POST /fastleo/files/crop` с `path`, `x`, `y`, `width`, `height` (пиксели картинки) и `ratio`
     * (`3:4`, по желанию) → `{"file": {path, url, name, image}}` — копия рядом, оригинал не меняется.
     *
     * @throws ValidationException
     */
    public function crop(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'path' => ['required', 'string'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'width' => ['required', 'integer', 'min:1'],
            'height' => ['required', 'integer', 'min:1'],
            'ratio' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,3}:[1-9][0-9]{0,3}$/'],
        ], ValidationMessages::all())->validate();

        $path = PathGuard::entry($request->input('path'));

        if ($path === null) {
            throw ValidationException::withMessages(['path' => __('fastleo::files.path_invalid')]);
        }

        $width = $request->integer('width');
        $height = $request->integer('height');
        $ratio = $request->input('ratio');

        if (is_string($ratio)) {
            [$ratioWidth, $ratioHeight] = array_map(intval(...), explode(':', $ratio));

            if (abs($width / $height - $ratioWidth / $ratioHeight) > 0.02 * $ratioWidth / $ratioHeight) {
                throw ValidationException::withMessages(['ratio' => __('fastleo::files.crop_ratio', ['ratio' => $ratio])]);
            }
        }

        try {
            $cropped = FileStore::crop($path, $request->integer('x'), $request->integer('y'), $width, $height);
        } catch (FileRejected $rejected) {
            throw ValidationException::withMessages(['path' => $rejected->getMessage()]);
        }

        return response()->json(['file' => ['path' => $cropped, 'url' => FileStore::url($cropped), 'name' => basename($cropped), 'image' => true]]);
    }

    /**
     * `DELETE /fastleo/files` с `paths[]` → `{"deleted": 2}`. Папки удаляются со всем
     * содержимым; сам корень и пути вне него пропускаются.
     *
     * @throws ValidationException
     */
    public function destroy(Request $request): JsonResponse
    {
        abort_unless(FileStore::canManage(), 403);

        Validator::make($request->all(), [
            'paths' => ['required', 'array', 'max:'.self::MAX_DELETE],
            'paths.*' => ['string'],
        ], ValidationMessages::all())->validate();

        $deleted = 0;

        foreach ((array) $request->input('paths') as $path) {
            $path = PathGuard::entry($path);

            if ($path !== null && FileStore::delete($path)) {
                $deleted++;
            }
        }

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * `GET /fastleo/files/thumb?path=uploads/a.jpg` — миниатюра (создаётся при первом показе).
     * Не удалось создать — переход на сам файл; путь вне корня или файла нет — 404.
     */
    public function thumb(Request $request): RedirectResponse
    {
        $path = PathGuard::entry($request->query('path'));
        abort_if($path === null || ! FileTypes::isImage($path), 404);

        $thumbnail = FileStore::thumbnail($path);

        if ($thumbnail !== null) {
            return redirect()->away(FileStore::url($thumbnail));
        }

        abort_unless(FileStore::disk()->fileExists($path), 404);

        return redirect()->away(FileStore::url($path));
    }

    /**
     * Хлебные крошки от корня до папки.
     *
     * @return list<array{name: string, path: string}>
     */
    private function breadcrumbs(string $root, string $folder): array
    {
        $crumbs = [['name' => __('fastleo::files.root'), 'path' => $root]];
        $relative = $folder === $root ? '' : substr($folder, strlen($root) + 1);
        $path = $root;

        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $path .= '/'.$segment;
            $crumbs[] = ['name' => $segment, 'path' => $path];
        }

        return $crumbs;
    }
}
