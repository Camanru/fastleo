<?php

declare(strict_types=1);

namespace Fastleo\Activity;

use RuntimeException;

/**
 * Откат не выполнен: запись изменилась, удалена или откатывать нечего. Текст понятен администратору.
 */
final class RevertFailed extends RuntimeException
{
    //
}
