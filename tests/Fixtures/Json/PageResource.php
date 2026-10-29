<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Json;

use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Page;

/**
 * Страницы с картинками-вариантами в элементах повторителя.
 */
final class PageResource extends Resource
{
    protected static string $model = Page::class;

    /** Адрес pages занят самой админкой. */
    protected static ?string $slug = 'site-pages';

    /**
     * Заголовок и повторитель с картинкой.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->translatable()->required(),
            Slug::make('slug')->from('title'),
            Repeater::make('faq')->fields([
                Text::make('question'),
                Image::make('icon')->directory('pages')->variants('card'),
                RichText::make('answer'),
                Gallery::make('photos')->directory('pages')->variants('card'),
            ]),
        ];
    }
}
