<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Closure;
use ErrorException;
use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Exceptions\ImageException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;

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

    /** Сколько секунд ждать занятую блокировку имени файла: запись файла — доли секунды. */
    private const int LOCK_WAIT = 5;

    /**
     * Диск файлов.
     */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(config()->string('fastleo.files.disk', 'public'));
    }

    /**
     * Пользователю доступен раздел «Файлы» (пункт меню, страница менеджера и удаление): Gate `manageFastleoFiles`, если сайт
     * его описал, иначе любому пользователю (при включённых ролях или `Fastleo::resolveOwnerUsing()` — только владельцу). Сами файлы Gate не прячет: окно выбора файла
     * в полях показывает все папки, а загрузка, создание папок и обрезка от Gate не зависят (при включённых ролях им нужно
     * право создания или изменения хоть в одном разделе).
     *
     * ```php
     * Gate::define('manageFastleoFiles', fn (User $user) => $user->is_owner);
     * ```
     */
    public static function canManage(): bool
    {
        return RoleMatrix::gate('manageFastleoFiles');
    }

    /**
     * Публичный адрес файла: `uploads/a.jpg` → `https://site.ru/storage/uploads/a.jpg`.
     */
    public static function url(string $path): string
    {
        $disk = self::disk();

        // Адрес локального и FTP-диска Laravel собирает из пути как есть: пробел, `#`, `?` и `%` в имени файла, залитого по FTP,
        // ломали бы ссылку. Облачные диски кодируют путь сами — их путь не трогаем, иначе он закодируется дважды.
        $adapter = $disk->getAdapter();
        // FTP и SFTP — необязательные пакеты: их классы называем строкой.
        $encode = $adapter instanceof LocalFilesystemAdapter || in_array($adapter::class, ['League\\Flysystem\\Ftp\\FtpAdapter', 'League\\Flysystem\\PhpseclibV3\\SftpAdapter'], true);

        return $disk->url($encode ? implode('/', array_map(rawurlencode(...), explode('/', $path))) : $path);
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
        $client = $file->getClientOriginalName();
        // В сообщениях имя показывается как есть, но байты не в UTF-8 заменяются на «?»: иначе JSON-ответ с ним не собирается (500).
        $original = mb_scrub($client);
        $name = FileName::sanitize($client);

        if (! FileTypes::isAllowed($name)) {
            throw FileRejected::because('rejected_extension', ['name' => $original]);
        }

        $disk = self::disk();

        if (! $disk->directoryExists($folder)) {
            $disk->makeDirectory($folder);
        }

        if (! FileTypes::isImage($name)) {
            return self::putUnique($disk, $folder, $name, fn (string $path): bool => $disk->putFileAs($folder, $file, basename($path)) !== false)
                ?? throw FileRejected::because('rejected_write', ['name' => $original]);
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
        } catch (ImageException|ErrorException) {
            // `ErrorException` — предупреждение декодера GIF, у которого кадр выходит за холст: файл читается по заголовку, но не декодируется.
            throw FileRejected::because('rejected_not_image', ['name' => $original]);
        }

        return self::putUnique($disk, $folder, $name, fn (string $path): bool => $disk->put($path, $binary) !== false)
            ?? throw FileRejected::because('rejected_write', ['name' => $original]);
    }

    /**
     * Свободное имя и запись под блокировкой: два одновременных файла с одним именем не займут одно место
     * (запись перезаписывает). Занятая блокировка ждётся до `LOCK_WAIT` секунд, а не обходится сразу. Кеш без блокировок
     * или блокировка, которая так и не освободилась, — запись без неё. Null — диск не принял файл.
     *
     * @param  Closure(string): bool  $write  записать по свободному пути
     */
    private static function putUnique(FilesystemAdapter $disk, string $folder, string $name, Closure $write): ?string
    {
        $claim = function () use ($disk, $folder, $name, $write): ?string {
            $path = FileName::unique($disk, $folder, $name);

            return $write($path) ? $path : null;
        };

        // Кеш без блокировок (своё хранилище сайта) даёт то же, что не освободившаяся блокировка: запись без неё.
        try {
            $claimed = Cache::getStore() instanceof LockProvider ? Cache::lock('fastleo.upload.'.md5($folder.'/'.$name), 30)->block(self::LOCK_WAIT, $claim) : false;
        } catch (LockTimeoutException) {
            $claimed = false;
        }

        if ($claimed === false) {
            return $claim();
        }

        return is_string($claimed) ? $claimed : null;
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

        // Картинку не удалось прочитать или закодировать (битый файл, формат, который не умеет GD) — один отказ; диск, который не пишет, — другой.
        try {
            $image = Images::manager(animation: false)->decodeBinary($binary);

            if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $image->width() || $y + $height > $image->height()) {
                throw FileRejected::because('crop_bounds');
            }

            $image->crop($width, $height, $x, $y);
            $extension = FileTypes::extension($path);
            $encoded = Images::encode($image, $extension);
            $target = self::putUnique($disk, dirname($path), pathinfo($path, PATHINFO_FILENAME).'-crop.'.$extension, fn (string $target): bool => $disk->put($target, $encoded) !== false)
                ?? throw FileRejected::because('rejected_write', ['name' => $name]);
        } catch (ImageException) {
            throw FileRejected::because('crop_not_image', ['name' => $name]);
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

        if (! $disk->makeDirectory($path)) {
            throw FileRejected::because('folder_failed', ['name' => $slug]);
        }

        return $path;
    }

    /**
     * Удалить файл или папку со всем содержимым вместе со служебными копиями.
     * Возвращает false, если такого пути нет.
     *
     * @throws FileRejected диск не дал удалить (нет прав, только чтение)
     */
    public static function delete(string $path): bool
    {
        $disk = self::disk();

        if ($disk->directoryExists($path)) {
            $files = Variants::names() === [] ? [] : array_values($disk->allFiles($path));

            if (! $disk->deleteDirectory($path)) {
                throw FileRejected::because('delete_failed', ['name' => basename($path)]);
            }

            $disk->deleteDirectory(self::SERVICE_ROOT.'/thumbs/'.$path);
            Variants::delete($path, $files);

            return true;
        }

        if (! $disk->fileExists($path)) {
            return false;
        }

        if (! $disk->delete([$path, self::SERVICE_ROOT.'/thumbs/'.$path.'.webp'])) {
            throw FileRejected::because('delete_failed', ['name' => basename($path)]);
        }

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

        // Готовая миниатюра годится, пока файл не заменили под тем же именем (загрузка по FTP, обновление на диске).
        if ($disk->fileExists($thumbnail) && (! $disk->fileExists($path) || $disk->lastModified($thumbnail) >= $disk->lastModified($path))) {
            return $thumbnail;
        }

        // GD без WebP миниатюру не сделает: менеджер покажет оригинал, а не ответит ошибкой сервера.
        if (! resolve(ImageSupport::class)->canEncodeWebp()) {
            return null;
        }

        $binary = FileTypes::isImage($path) && $disk->fileExists($path) ? (string) $disk->get($path) : '';

        if ($binary === '' || Images::problem($binary) !== null) {
            return null;
        }

        try {
            // Диск не принял миниатюру: путь несуществующего файла вернуть нельзя — менеджер покажет оригинал.
            if ($disk->put($thumbnail, Images::thumbnail($binary)) === false) {
                return null;
            }
        } catch (ImageException) {
            return null;
        }

        return $thumbnail;
    }
}
