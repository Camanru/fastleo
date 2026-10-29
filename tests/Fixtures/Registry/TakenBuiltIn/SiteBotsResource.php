<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\TakenBuiltIn;

use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел сайта, занявший адрес встроенного раздела «Уведомления».
 */
final class SiteBotsResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'notification-bots';

    /**
     * Без полей.
     */
    public function fields(): array
    {
        return [];
    }
}
