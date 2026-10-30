<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Fastleo\Fastleo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Exceptions\ImageException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;

/**
 * Файлы на диске `fastleo.files.disk` внутри корня загрузок.
 *
 * Пути на входе уже проверены `PathGuard`. Служебные копии (миниатюры менеджера) лежат
 * вне корня, в `fastleo/thumbs/{путь}.webp` и `fastleo/variants/…`: менеджер их не показывает, а при удалении
 * файла или папки они удаляются вместе с ним.
 */
final class FileStore
{
    /** Папка служебных копий на диске. */
    public const string SERVICE_ROOT = 'fastleo';

    /**
     * Диск файлов.
     */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(config()->string('fastleo.files.disk', 'public'));
    }

    /**
     * Админу доступен раздел «Файлы» (пункт меню, страница менеджера и удаление): Gate `manageFastleoFiles`, если сайт
     * его описал, иначе любому админу. Сами файлы Gate не прячет: окно выбора файла в полях показывает все папки,
     * а загрузка, создание папок и обрезка от Gate не зависят.
     *
     * ```php
     * Gate::define('manageFastleoFiles', fn (User $user) => $user->is_owner);
     * ```
     */
    public static function canManage(): bool
    {
        return ! Gate::has('manageFastleoFiles') || Gate::forUser(Fastleo::guard()->user())->allows('manageFastleoFiles');
    }

    /**
     * Публичный адрес файла: `uploads/a.jpg` → `https://site.ru/storage/uploads/a.jpg`.
     */
    public static function url(string $path): string
    {
        return self::disk()->url($path);
    }

    /**
     * Путь или имя скрыты настройкой `fastleo.files.hidden`: менеджер не показывает их и не удаляет по отдельности.
     * Проверяется каждая часть пути: всё внутри скрытой папки тоже скрыто. Часть сравнивается целиком, `*` заменяет любые символы: `thumbs`, `*.bak`.
     *
     * ```php
     * FileStore::isHidden('uploads/thumbs/a.jpg'); // true при 'hidden' => ['thumbs']
     * ```
     */
    public static function isHidden(string $path): bool
    {
        $patterns = array_values(array_filter(Arr::wrap(config('fastleo.files.hidden', [])), is_string(...)));

        if ($patterns === []) {
            return false;
        }

        return array_any(explode('/', $path), fn (string $segment): bool => $segment !== '' && Str::is($patterns, $segment));
    }

    /**
     * Содержимое папки: вложенные папки и файлы по имени.
     *
     * @return array{folders: list<array{name: string, path: string}>, files: list<array{name: string, path: string, url: string, size: int, image: bool}>}
     */
    public static function listing(string $folder): array
    {
        $folders = [];
        $files = [];

        foreach (self::disk()->getDriver()->listContents($folder, false) as $entry) {
            $path = $entry->path();
            $name = basename($path);

            if (str_starts_with($name, '.') || self::isHidden($name)) {
                continue;
            }

            if ($entry instanceof DirectoryAttributes) {
                $folders[] = ['name' => $name, 'path' => $path];
            } elseif ($entry instanceof FileAttributes) {
                $files[] = [
                    'name' => $name,
                    'path' => $path,
                    'url' => self::url($path),
                    'size' => $entry->fileSize() ?? 0,
                    'image' => FileTypes::isImage($path),
                ];
            }
        }

        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['folders' => $folders, 'files' => $files];
    }

    /**
     * Сохранить загруженный файл в папку и вернуть его путь.
     *
     * Картинка проверяется по заголовку и пересохраняется (`Images::normalize`); файл,
     * который не декодируется как картинка, не сохраняется.
     *
     * @throws FileRejected
     */
    public static function store(UploadedFile $file, string $folder): string
    {
        $original = $file->getClientOriginalName();
        $name = FileName::sanitize($original);

        if (! FileTypes::isAllowed($name)) {
            throw FileRejected::because('rejected_extension', ['name' => $original]);
        }

        $disk = self::disk();

        if (! $disk->directoryExists($folder)) {
            $disk->makeDirectory($folder);
        }

        if (! FileTypes::isImage($name)) {
            $path = FileName::unique($disk, $folder, $name);

            if ($disk->putFileAs($folder, $file, basename($path)) === false) {
                throw FileRejected::because('rejected_write', ['name' => $original]);
            }

            return $path;
        }

        $binary = (string) $file->get();
        $problem = Images::problem($binary);

        if ($problem !== null) {
            throw FileRejected::because('rejected_'.$problem, [
                'name' => $original,
                'pixels' => (int) round(config()->integer('fastleo.files.max_pixels', 32_000_000) / 1_000_000),
            ]);
        }

        try {
            $binary = Images::normalize($binary, FileTypes::extension($name));
        } catch (ImageException) {
            throw FileRejected::because('rejected_not_image', ['name' => $original]);
        }

        $path = FileName::unique($disk, $folder, $name);

        if (! $disk->put($path, $binary)) {
            throw FileRejected::because('rejected_write', ['name' => $original]);
        }

        return $path;
    }

    /**
     * Вырезать из картинки прямоугольник и сохранить копией рядом: `photo.jpg` → `photo-crop.jpg`
     * (`photo-crop-1.jpg`, если занято). Оригинал не меняется. Координаты — в пикселях картинки.
     *
     * @throws FileRejected не картинка, файла нет или рамка выходит за картинку
     */
    public static function crop(string $path, int $x, int $y, int $width, int $height): string
    {
        $disk = self::disk();
        $binary = FileTypes::isImage($path) && $disk->fileExists($path) ? (string) $disk->get($path) : '';
        $name = basename($path);

        if ($binary === '' || Images::problem($binary) !== null) {
            throw FileRejected::because('crop_not_image', ['name' => $name]);
        }

        try {
            $image = Images::manager(animation: false)->decodeBinary($binary);
        } catch (ImageException) {
            throw FileRejected::because('crop_not_image', ['name' => $name]);
        }

        if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $image->width() || $y + $height > $image->height()) {
            throw FileRejected::because('crop_bounds');
        }

        $image->crop($width, $height, $x, $y);
        $extension = FileTypes::extension($path);
        $target = FileName::unique($disk, dirname($path), pathinfo($path, PATHINFO_FILENAME).'-crop.'.$extension);

        try {
            $disk->put($target, Images::encode($image, $extension));
        } catch (ImageException) {
            throw FileRejected::because('rejected_write', ['name' => $name]);
        }

        return $target;
    }

    /**
     * Создать папку в родительской и вернуть её путь.
     *
     * @throws FileRejected имя пустое после очистки или папка уже есть
     */
    public static function makeFolder(string $parent, string $name): string
    {
        $slug = FileName::folder($name);

        if ($slug === '') {
            throw FileRejected::because('folder_name');
        }

        $path = $parent.'/'.$slug;
        $disk = self::disk();

        if ($disk->exists($path)) {
            throw FileRejected::because('folder_exists', ['name' => $slug]);
        }

        $disk->makeDirectory($path);

        return $path;
    }

    /**
     * Удалить файл или папку со всем содержимым вместе со служебными копиями.
     * Возвращает false, если такого пути нет.
     */
    public static function delete(string $path): bool
    {
        $disk = self::disk();

        if ($disk->directoryExists($path)) {
            $files = Variants::names() === [] ? [] : array_values($disk->allFiles($path));
            $disk->deleteDirectory($path);
            $disk->deleteDirectory(self::SERVICE_ROOT.'/thumbs/'.$path);
            Variants::delete($path, $files);

            return true;
        }

        if (! $disk->fileExists($path)) {
            return false;
        }

        $disk->delete([$path, self::SERVICE_ROOT.'/thumbs/'.$path.'.webp']);
        Variants::delete($path);

        return true;
    }

    /**
     * Путь миниатюры менеджера, создаёт её при первом обращении; null — не картинка
     * или файла нет.
     */
    public static function thumbnail(string $path): ?string
    {
        $disk = self::disk();
        $thumbnail = self::SERVICE_ROOT.'/thumbs/'.$path.'.webp';

        if ($disk->fileExists($thumbnail)) {
            return $thumbnail;
        }

        $binary = FileTypes::isImage($path) && $disk->fileExists($path) ? (string) $disk->get($path) : '';

        if ($binary === '' || Images::problem($binary) !== null) {
            return null;
        }

        try {
            $disk->put($thumbnail, Images::thumbnail($binary));
        } catch (ImageException) {
            return null;
        }

        return $thumbnail;
    }
}
