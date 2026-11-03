<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorLimits;

use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с темами: тем в базе много, поэтому при поиске по серверу форма присылает только выбранное.
 */
final class WideTagsResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'wide-tags';

    /**
     * Название и темы.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            BelongsToMany::make('topics', 'Темы'),
        ];
    }
}
