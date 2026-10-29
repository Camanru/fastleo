<?php

declare(strict_types=1);

namespace Fastleo\Files;

use RuntimeException;

/**
 * Файл или папку нельзя сохранить; текст сообщения показывается админу.
 */
final class FileRejected extends RuntimeException
{
    /**
     * Отказ с текстом из `fastleo::files.{key}`.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('fastleo::files.'.$key, $replace));
    }
}
