<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\NoModel;

use Fastleo\Resource;

/**
 * Ресурс без модели.
 */
final class NoModelResource extends Resource
{
    /**
     * Без полей.
     */
    public function fields(): array
    {
        return [];
    }
}
