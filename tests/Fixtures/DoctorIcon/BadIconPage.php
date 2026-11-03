<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorIcon;

use Fastleo\Page;

/**
 * Страница с иконкой, которой нет в наборе Lucide.
 */
final class BadIconPage extends Page
{
    protected static ?string $slug = 'bad-page-icon';

    protected static string $icon = 'message-square-star';
}
