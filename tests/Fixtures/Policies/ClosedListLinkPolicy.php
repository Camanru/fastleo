<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\ArticleLink;
use Workbench\App\Models\User;

/**
 * Полезные ссылки: список закрыт, а запись можно смотреть, менять и переставлять — проверяется именно право на список.
 */
final class ClosedListLinkPolicy
{
    /**
     * Список закрыт.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Запись видна.
     */
    public function view(User $user, ArticleLink $link): bool
    {
        return true;
    }

    /**
     * Менять можно.
     */
    public function update(User $user, ArticleLink $link): bool
    {
        return true;
    }

    /**
     * Переставлять можно.
     */
    public function reorder(User $user, ArticleLink $link): bool
    {
        return true;
    }
}
