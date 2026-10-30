<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Переключатель у поля, которого нет в форме (только в списке) — он не сработает.
 */
final class ListOnlyArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'listonly-articles';

    /**
     * Заголовок и поле только для списка.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Toggle::make('is_published', 'Опубликовано')->onlyInList()->quickToggle(),
        ];
    }
}
