<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorBroken;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\BrokenRelationArticle;

/**
 * Раздел со связями, которые не собираются: нет метода связи, нет таблицы связанной модели, блок внутри блока.
 */
final class BrokenRelationsResource extends Resource
{
    protected static string $model = BrokenRelationArticle::class;

    protected static ?string $slug = 'broken-relations';

    /**
     * Список вариантов и блоки, у которых нельзя посчитать записи или собрать форму.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('category_id')->relation('ghost'),
            BelongsToMany::make('ghosts'),
            HasMany::make('absent')->fields([Text::make('title')]),
            HasMany::make('ghostLinks')->fields([Text::make('title')]),
            HasMany::make('links')->fields([HasMany::make('links')->fields([Text::make('title')])]),
        ];
    }
}
