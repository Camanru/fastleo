<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Activity;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Topic;

/**
 * Раздел тем, который не ведёт журнал изменений.
 */
final class QuietTopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static bool $activity = false;

    /**
     * Название темы.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }
}
