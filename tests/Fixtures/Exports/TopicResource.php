<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Exports;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Topic;

/**
 * Темы без выгрузки.
 */
final class TopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static bool $exportable = false;

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')];
    }
}
