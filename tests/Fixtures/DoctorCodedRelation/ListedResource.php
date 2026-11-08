<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorCodedRelation;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\CodedArticle;

/**
 * Раздел со связью по коду категории (coded-list): поле пишется только в разделе «coded-writable».
 */
final class ListedResource extends Resource
{
    protected static string $model = CodedArticle::class;

    protected static ?string $slug = 'coded-list';

    protected static bool $readonly = false;

    /**
     * Название и категория по коду.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('category_id')->relation('categoryByCode')->onlyInList(),
        ];
    }
}
