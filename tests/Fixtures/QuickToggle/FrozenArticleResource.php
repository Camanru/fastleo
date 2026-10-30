<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Actions\SetToggle;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, где переключатель включён у поля только для чтения и у скрытого поля.
 */
final class FrozenArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'frozen-articles';

    /**
     * Заголовок, поле только для чтения и скрытое поле — оба с переключателем.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Toggle::make('is_published', 'Опубликовано')->quickToggle()->readonly(),
            Toggle::make('is_featured', 'На главной')->quickToggle()->hidden(),
        ];
    }

    /**
     * Действия на то же поле только для чтения и на скрытое поле.
     */
    public function actions(): array
    {
        return [
            SetToggle::make('is_published', 'Опубликовать', true),
            SetToggle::make('is_featured', 'На главную', true),
        ];
    }
}
