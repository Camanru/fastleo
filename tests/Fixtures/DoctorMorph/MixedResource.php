<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Remark;

/**
 * Разделы с числовым и строковым ключом.
 */
final class MixedResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $slug = 'mixed';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class, ThingResource::class])];
    }
}
