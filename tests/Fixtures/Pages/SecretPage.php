<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Pages;

use Fastleo\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Страница тестов только для owner@example.com.
 */
final class SecretPage extends Page
{
    /**
     * Шаблон из папки тестов.
     */
    #[Override]
    public function view(): string
    {
        return 'pages-fixture::stats';
    }

    /**
     * Доступ по email.
     */
    #[Override]
    public function canAccess(?Authenticatable $user): bool
    {
        return $user instanceof Model && $user->getAttribute('email') === 'owner@example.com';
    }
}
