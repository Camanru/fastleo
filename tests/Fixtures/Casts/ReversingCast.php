<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Каст сайта, который пишет значение в базу преобразованным (вместо шифрования в тестах — разворот строки).
 *
 * @implements CastsAttributes<string, string>
 */
final class ReversingCast implements CastsAttributes
{
    /**
     * Прочитать значение из базы.
     */
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return strrev((string) $value);
    }

    /**
     * Записать значение в базу.
     */
    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return strrev((string) $value);
    }
}
