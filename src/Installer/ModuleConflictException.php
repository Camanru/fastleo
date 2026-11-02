<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use RuntimeException;

/**
 * Установка модулей не начата: файл, таблица или адрес уже заняты.
 */
final class ModuleConflictException extends RuntimeException
{
    /**
     * @param  list<string>  $conflicts  описание каждого конфликта
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(implode("\n", $conflicts));
    }
}
