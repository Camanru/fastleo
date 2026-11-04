<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Nested;

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Image;
use Fastleo\Fields\Number;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;
use Workbench\App\Models\Page;

/**
 * Страницы со списками внутри элементов: Repeater в блоке, Repeater и Blocks в элементе повторителя.
 */
final class PageResource extends Resource
{
    protected static string $model = Page::class;

    /** Адрес pages занят самой админкой. */
    protected static ?string $slug = 'site-pages';

    /**
     * Заголовок, повторитель с шагами и дополнениями, конструктор с тарифами.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->translatable()->required(),
            Slug::make('slug')->from('title'),
            Repeater::make('faq')->fields([
                Text::make('question')->required(),
                Select::make('kind')->options(['text' => 'Текст', 'note' => 'Заметка']),
                Select::make('mode')->options(['plain' => 'Обычный', 'extra' => 'Особый']),
                Text::make('remark')->visibleWhen('mode', 'extra'),
                Repeater::make('steps')->fields([
                    Select::make('mode')->options(['plain' => 'Обычный', 'extra' => 'Особый']),
                    Text::make('title')->required(),
                    Image::make('icon')->directory('pages')->variants('card'),
                    Text::make('extra')->visibleWhen('mode', 'extra')->required(),
                ])->max(5),
                Blocks::make('extras')->blocks([
                    Block::make('note', 'Заметка')->fields([Textarea::make('text')->required()]),
                    Block::make('photo', 'Фото')->fields([Image::make('src')->directory('pages')->variants('card')]),
                ])->max(5),
            ]),
            Blocks::make('content')->blocks([
                Block::make('text', 'Текст')->fields([RichText::make('body')->required()]),
                Block::make('plans', 'Тарифы')->fields([
                    Text::make('title')->required(),
                    Repeater::make('items')->fields([
                        Text::make('name')->required(),
                        Number::make('price'),
                        RichText::make('about'),
                    ])->max(5),
                ]),
            ])->max(10),
        ];
    }
}
