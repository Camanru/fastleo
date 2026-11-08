<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;

/**
 * Диск, который читает, но не создаёт папок и не удаляет ничего (смонтирован только для чтения, нет прав на папку).
 * Без `throw` Laravel превращает такой сбой в `false`, как и на сайте с настройкой по умолчанию.
 *
 * ```php
 * Storage::set('public', UndeletableDisk::at($root));
 * ```
 */
final class UndeletableDisk extends LocalFilesystemAdapter
{
    /**
     * Диск Laravel поверх каталога `$root`, где нельзя создавать папки и удалять.
     */
    public static function at(string $root): FilesystemAdapter
    {
        $adapter = new self($root);

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false, 'url' => '/storage']);
    }

    /**
     * Создание папки не удаётся.
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'read-only');
    }

    /**
     * Удаление файла не удаётся.
     */
    public function delete(string $path): void
    {
        throw UnableToDeleteFile::atLocation($path, 'read-only');
    }

    /**
     * Удаление папки не удаётся.
     */
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'read-only');
    }
}
