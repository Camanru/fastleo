<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Notifications\Leads;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел «Заявки» сайта (адрес `leads`, как у модуля) — для проверки doctor, что о заявках кто-то узнает.
 */
final class LeadResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'leads';

    /**
     * Одно поле.
     */
    public function fields(): array
    {
        return [Text::make('name')];
    }
}
