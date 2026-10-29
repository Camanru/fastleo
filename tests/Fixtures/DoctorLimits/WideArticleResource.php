<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorLimits;

use Fastleo\Fields\HasMany;
use Fastleo\Fields\Text;
use Fastleo\Fields\Url;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с вложенными ссылками, которых может быть столько, что форма не поместится в max_input_vars.
 */
final class WideArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'wide-articles';

    /**
     * Название и до 500 ссылок.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            HasMany::make('links', 'Ссылки')->fields([Text::make('title')->required(), Url::make('url')])->max(500),
        ];
    }
}
