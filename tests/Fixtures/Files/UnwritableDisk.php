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
 * Storage::set('public', UnwritableDisk::at($root, only: 'manifest.json')); // не пишется только этот файл
 * ```
 */
final class UnwritableDisk extends LocalFilesystemAdapter
{
    /** Окончание пути единственного файла, который не записывается; `null` — не записывается ни один. */
    private ?string $only = null;

    /**
     * Диск Laravel поверх каталога `$root`, где нельзя записывать файлы (или только файлы с путём на `$only`).
     */
    public static function at(string $root, ?string $only = null): FilesystemAdapter
    {
        $adapter = new self($root);
        $adapter->only = $only;

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false, 'url' => '/storage']);
    }

    /**
     * Запись строки не удаётся.
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->assertWritable($path);

        parent::write($path, $contents, $config);
    }

    /**
     * Запись потока не удаётся.
     *
     * @param  resource  $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->assertWritable($path);

        parent::writeStream($path, $contents, $config);
    }

    /**
     * @throws UnableToWriteFile путь попадает под запрет
     */
    private function assertWritable(string $path): void
    {
        if ($this->only === null || str_ends_with($path, $this->only)) {
            throw UnableToWriteFile::atLocation($path, 'disk is full');
        }
    }
}
