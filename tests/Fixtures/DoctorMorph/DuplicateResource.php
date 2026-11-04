<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Remark;

/**
 * Заметки, у поля которых два раздела одной модели.
 */
final class DuplicateResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $slug = 'duplicate';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class, DraftArticleResource::class])];
    }
}
