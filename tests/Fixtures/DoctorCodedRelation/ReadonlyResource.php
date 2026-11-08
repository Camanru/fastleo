<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorCodedRelation;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\CodedArticle;

/**
 * Раздел со связью по коду категории (coded-readonly): поле пишется только в разделе «coded-writable».
 */
final class ReadonlyResource extends Resource
{
    protected static string $model = CodedArticle::class;

    protected static ?string $slug = 'coded-readonly';

    protected static bool $readonly = true;

    /**
     * Название и категория по коду.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('category_id')->relation('categoryByCode'),
        ];
    }
}
