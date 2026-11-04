<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\GuardedRemark;

/**
 * Заметка, колонки объекта которой не внесены в $fillable.
 */
final class NotFillableResource extends Resource
{
    protected static string $model = GuardedRemark::class;

    protected static ?string $slug = 'not-fillable';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class])];
    }
}
