<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RichTranslated;

use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Page;

/**
 * Страницы с форматированным текстом на нескольких языках и внутри повторителя.
 */
final class PageResource extends Resource
{
    protected static string $model = Page::class;

    /** Адрес pages занят самой админкой. */
    protected static ?string $slug = 'rich-pages';

    /**
     * Заголовок-текст на двух языках и вопросы с форматированным ответом.
     */
    public function fields(): array
    {
        return [
            RichText::make('title', 'Заголовок')->translatable(),
            Slug::make('slug'),
            Repeater::make('faq', 'Вопросы')->fields([
                Text::make('question', 'Вопрос'),
                RichText::make('answer', 'Ответ'),
            ]),
        ];
    }
}
