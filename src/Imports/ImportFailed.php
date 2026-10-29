<?php

declare(strict_types=1);

namespace Fastleo\Imports;

use RuntimeException;

/**
 * Файл импорта нельзя обработать целиком (не читается, нет известных столбцов).
 */
final class ImportFailed extends RuntimeException {}
