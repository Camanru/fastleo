<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Enums;

/**
 * Enum без значений (не backed) — для проверки списков, которые берут имя варианта.
 */
enum PlainPriority
{
    case High;
    case Low;
}
