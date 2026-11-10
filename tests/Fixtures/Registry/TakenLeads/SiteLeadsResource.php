<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\TakenLeads;

use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел сайта, занявший адрес встроенного раздела «Заявки».
 */
final class SiteLeadsResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'leads';

    /**
     * Без полей.
     */
    public function fields(): array
    {
        return [];
    }
}
