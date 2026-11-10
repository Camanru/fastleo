<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Статьи: Policy разрешает всё, включая свои `export`, `import` и `reorder` — раздел сам закрывает их владельцу.
 */
final class AllowEverythingArticlePolicy
{
    /**
     * Список.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Выгрузка (своя способность).
     */
    public function export(User $user): bool
    {
        return true;
    }

    /**
     * Загрузка (своя способность).
     */
    public function import(User $user): bool
    {
        return true;
    }

    /**
     * Порядок (своя способность).
     */
    public function reorder(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Просмотр.
     */
    public function view(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Создание.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Изменение.
     */
    public function update(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Удаление.
     */
    public function delete(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Восстановление.
     */
    public function restore(User $user, Article $article): bool
    {
        return true;
    }

    /**
     * Удаление навсегда.
     */
    public function forceDelete(User $user, Article $article): bool
    {
        return true;
    }
}
