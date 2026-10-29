<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Search;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Topic;

/**
 * Раздел тем, который глобальный поиск не просматривает (в списке раздела поиск работает).
 */
final class HiddenTopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static bool $globallySearchable = false;

    /**
     * Название темы.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()->searchable()];
    }
}
