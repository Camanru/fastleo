<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\LooseRemark;

/**
 * Заметка, в таблице которой нет колонок объекта.
 */
final class NoColumnsResource extends Resource
{
    protected static string $model = LooseRemark::class;

    protected static ?string $slug = 'no-columns';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class])];
    }
}
