<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use RuntimeException;

/**
 * Файл модуля записать не удалось (нет прав, диск полон): уже записанные файлы удалены.
 */
final class ModuleWriteException extends RuntimeException {}
