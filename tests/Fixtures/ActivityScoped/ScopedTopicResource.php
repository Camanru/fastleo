<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ActivityScoped;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Topic;

/**
 * Раздел тем, в котором администратору видны только темы «Видимая тема».
 */
final class ScopedTopicResource extends Resource
{
    protected static string $model = Topic::class;

    /**
     * Название темы.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Только видимые темы.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function query(Builder $query): Builder
    {
        return $query->where('title', 'Видимая тема');
    }
}
