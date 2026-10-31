<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/**
 * Диск, который читает и создаёт папки, но не может записать ни одного файла (кончилось место, нет прав).
 * Без `throw` Laravel превращает такой сбой в `false`, как и на сайте с настройкой по умолчанию.
 *
 * ```php
 * Storage::set('public', UnwritableDisk::at($root));
 * ```
 */
final class UnwritableDisk extends LocalFilesystemAdapter
{
    /**
     * Диск Laravel поверх каталога `$root`, где нельзя записывать файлы.
     */
    public static function at(string $root): FilesystemAdapter
    {
        $adapter = new self($root);

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false, 'url' => '/storage']);
    }

    /**
     * Запись строки всегда не удаётся.
     */
    public function write(string $path, string $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'disk is full');
    }

    /**
     * Запись потока всегда не удаётся.
     *
     * @param  resource  $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'disk is full');
    }
}
