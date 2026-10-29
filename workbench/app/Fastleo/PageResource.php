<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Layout\Grid;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Page;

/**
 * Демо-раздел «Страницы»: многоязычный заголовок, повторитель, пары «название — значение», конструктор из блоков.
 */
final class PageResource extends Resource
{
    protected static string $model = Page::class;

    /** Адрес pages занят самой админкой. */
    protected static ?string $slug = 'site-pages';

    protected static ?string $label = 'Страницы';

    protected static ?string $singular = 'Страница';

    protected static string $icon = 'file-text';

    protected static ?string $group = 'Контент';

    protected static int $position = 40;

    /**
     * Заголовок, адрес, вопросы и ответы, характеристики.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->translatable()->required()->searchable()->sortable(),
            Slug::make('slug', 'Адрес')->from('title'),
            Repeater::make('faq', 'Вопросы и ответы')->fields([
                Grid::make(2, [
                    Text::make('question', 'Вопрос')->required(),
                    Image::make('icon', 'Иконка')->directory('pages'),
                ]),
                Textarea::make('answer', 'Ответ')->rows(3),
                Toggle::make('open', 'Раскрыт сразу'),
            ])->max(20),
            KeyValue::make('specs', 'Характеристики')->keyLabel('Параметр')->valueLabel('Значение')->max(30),
            Blocks::make('content', 'Содержимое')->blocks([
                Block::make('text', 'Текст')->icon('text')->fields([RichText::make('body', 'Текст')->required()]),
                Block::make('image', 'Картинка')->icon('image')->fields([
                    Image::make('src', 'Картинка')->directory('pages')->variants('card')->required(),
                    Text::make('caption', 'Подпись'),
                ]),
                Block::make('gallery', 'Галерея')->icon('images')->fields([Gallery::make('photos', 'Фотографии')->directory('pages')->variants('card')->max(12)]),
                Block::make('quote', 'Цитата')->icon('quote')->fields([
                    Textarea::make('text', 'Цитата')->required(),
                    Text::make('author', 'Автор'),
                ]),
            ])->max(30),
        ];
    }

    /**
     * Страница на демо-сайте.
     */
    public function url(Model $record): string
    {
        return route('site-pages.show', $record->getAttribute('slug'));
    }
}
