<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Второй раздел той же модели статей (например, черновики).
 */
final class DraftArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'draft-articles';

    /**
     * Заголовок.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Заголовок')];
    }
}
