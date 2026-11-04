<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Remark;

/**
 * Заметки, у которых всё описано верно.
 */
final class FineResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $slug = 'fine';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class])];
    }
}
