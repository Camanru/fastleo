<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\ArticleLink;
use Workbench\App\Models\User;

/**
 * Полезные ссылки: смотреть можно, менять и переставлять нельзя.
 */
final class NoUpdateLinkPolicy
{
    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись видна.
     */
    public function view(User $user, ArticleLink $link): bool
    {
        return true;
    }

    /**
     * Менять нельзя.
     */
    public function update(User $user, ArticleLink $link): bool
    {
        return false;
    }
}
