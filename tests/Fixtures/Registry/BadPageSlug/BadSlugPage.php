<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\BadPageSlug;

use Fastleo\Page;

/**
 * Страница с недопустимым адресом.
 */
final class BadSlugPage extends Page
{
    protected static ?string $slug = 'Bad_Page';
}
