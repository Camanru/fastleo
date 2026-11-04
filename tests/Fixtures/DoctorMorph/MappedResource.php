<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Remark;

/**
 * Заметки, когда сайт требует карту типов.
 */
final class MappedResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $slug = 'mapped';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class])];
    }
}
