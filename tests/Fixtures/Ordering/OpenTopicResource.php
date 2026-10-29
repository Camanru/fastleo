<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Ordering;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Topic;

/**
 * Темы в ручном порядке, видны только «Открытые…».
 */
final class OpenTopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static ?string $slug = 'open-topics';

    protected static ?string $sortColumn = 'sort';

    /**
     * Только темы, начинающиеся с «Открытая».
     */
    #[\Override]
    public function query(Builder $query): Builder
    {
        return $query->where('title', 'like', 'Открытая%');
    }

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title')->required()->searchable()->sortable()];
    }
}
