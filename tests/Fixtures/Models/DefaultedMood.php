<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

/**
 * Варианты настроения; колонка в таблице тестов по умолчанию содержит «new» — такого варианта здесь нет.
 */
enum DefaultedMood: string
{
    case Calm = 'calm';
    case Angry = 'angry';
}
