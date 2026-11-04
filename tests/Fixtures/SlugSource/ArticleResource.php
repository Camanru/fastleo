<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SlugSource;

use Fastleo\Fields\Hidden;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых заголовок — источник адреса, но его нельзя менять: адрес из присланного заголовка автоматическим не считается.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Заголовок только для чтения и адрес из него.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->readonly(),
            Slug::make('slug', 'Адрес')->from('title'),
            Hidden::make('type')->default('news'),
        ];
    }
}
