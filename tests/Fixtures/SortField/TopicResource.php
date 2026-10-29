<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SortField;

use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\SortedTopic;

/**
 * Темы, у которых колонка порядка ещё и обычное поле формы.
 */
final class TopicResource extends Resource
{
    protected static string $model = SortedTopic::class;

    protected static ?string $sortColumn = 'sort';

    /**
     * Название и порядок.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Number::make('sort', 'Порядок')->integer()->min(0),
        ];
    }
}
