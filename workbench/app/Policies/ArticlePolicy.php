<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Права на статьи демо-сайта: опубликованную статью нельзя удалить, остальное можно.
 */
final class ArticlePolicy
{
    /**
     * Список статей.
     */
    public function viewAny(): bool
    {
        return true;
    }

    /**
     * Просмотр статьи.
     */
    public function view(): bool
    {
        return true;
    }

    /**
     * Создание статьи.
     */
    public function create(): bool
    {
        return true;
    }

    /**
     * Изменение статьи.
     */
    public function update(): bool
    {
        return true;
    }

    /**
     * Удаление в корзину: только неопубликованные.
     */
    public function delete(User $user, Article $article): bool
    {
        return ! $article->is_published;
    }

    /**
     * Восстановление из корзины.
     */
    public function restore(): bool
    {
        return true;
    }

    /**
     * Удаление навсегда.
     */
    public function forceDelete(): bool
    {
        return true;
    }
}
