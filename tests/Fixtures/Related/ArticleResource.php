<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Related;

use Fastleo\Actions\SetToggle;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи — дочерний раздел рубрик: название с поиском и сортировкой, цветной статус, рубрика, действие «Важная» и действие с мини-формой.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    /**
     * Поля списка и формы.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable()->sortable(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->badges()->sortable(),
            BelongsTo::make('category_id', 'Рубрика')->required(),
            Toggle::make('is_featured', 'Важная')->hideInList(),
        ];
    }

    /**
     * Действие над статьёй из строки таблицы.
     */
    public function actions(): array
    {
        return [SetToggle::make('is_featured', 'Сделать важной', true)->icon('star'), new StampArticles];
    }
}
