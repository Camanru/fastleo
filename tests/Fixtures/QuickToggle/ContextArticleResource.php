<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Actions\SetToggle;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Переключатели, правила которых смотрят на другие поля записи: статью с заголовком «Нельзя» публиковать нельзя,
 * избранной может быть только опубликованная. Значение анонса не читается.
 */
final class ContextArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'context-articles';

    /**
     * Заголовок, переключатели с правилами `prohibited_if:title,Нельзя` и `same:is_published`, нечитаемый анонс.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Toggle::make('is_published', 'Опубликовано')->rules(['boolean', 'prohibited_if:title,Нельзя'])->quickToggle(),
            Toggle::make('is_featured', 'Избранное')->rules(['boolean', 'same:is_published'])->quickToggle(),
            UnreadableField::make('excerpt', 'Анонс')->hideInList(),
        ];
    }

    /**
     * Массовая публикация.
     */
    public function actions(): array
    {
        return [SetToggle::make('is_published', 'Опубликовать', true)];
    }
}
