<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с именем, как у класса пакета `Fastleo\Resource` — проверка псевдонима в созданном ресурсе.
 */
#[Table('categories')]
final class Resource extends Model {}
