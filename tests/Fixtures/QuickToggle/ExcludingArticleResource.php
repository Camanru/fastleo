<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, у которых поля правятся на месте, но правило `exclude_if` исключает их, пока заголовок «Заморожено».
 */
final class ExcludingArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'excluding-articles';

    /**
     * Заголовок, переключатель и число с правилом `exclude_if`.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Toggle::make('is_featured', 'Избранное')->rules(['boolean', 'exclude_if:title,Заморожено'])->quickToggle(),
            Number::make('views', 'Просмотры')->integer()->rules(['exclude_if:title,Заморожено'])->inlineEditable(),
        ];
    }
}
