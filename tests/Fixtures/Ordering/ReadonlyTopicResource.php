<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Ordering;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Topic;

/**
 * Темы в ручном порядке, раздел только для просмотра.
 */
final class ReadonlyTopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static ?string $slug = 'readonly-topics';

    protected static ?string $sortColumn = 'sort';

    protected static bool $readonly = true;

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title')];
    }
}
