<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Actions\SetToggle;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с быстрым переключателем «Опубликовано» и готовыми действиями «Показать» / «Скрыть» — для тестов переключателя.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    /**
     * Заголовок, переключатель в списке и обычная галочка.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Toggle::make('is_published', 'Опубликовано')->quickToggle(),
            Toggle::make('is_featured', 'На главной'),
        ];
    }

    /**
     * Показать и скрыть на сайте.
     */
    public function actions(): array
    {
        return [
            SetToggle::make('is_published', 'Опубликовать', true),
            SetToggle::make('is_published', 'Снять с публикации', false)->icon('archive'),
        ];
    }
}
