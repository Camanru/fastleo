<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ListUx;

use Workbench\App\Models\Product;
use Workbench\App\Models\User;

/**
 * Товар «Закрытый» изменять нельзя.
 */
final class ClosedProductPolicy
{
    /**
     * Любой товар виден.
     */
    public function view(): bool
    {
        return true;
    }

    /**
     * Менять можно все, кроме «Закрытого».
     */
    public function update(User $user, Product $product): bool
    {
        return $product->title !== 'Закрытый';
    }
}
