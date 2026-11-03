<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Policies;

use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Статьи: всё можно, но число вопросов «можно ли смотреть запись» считается.
 */
final class CountingArticlePolicy
{
    /** Сколько раз спросили `view`. */
    public static int $views = 0;

    /**
     * Список виден.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Запись видна; вопрос запоминается.
     */
    public function view(User $user, Article $article): bool
    {
        self::$views++;

        return true;
    }
}
