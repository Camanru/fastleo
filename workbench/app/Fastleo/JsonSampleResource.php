<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Image;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Markdown;
use Fastleo\Fields\Number;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Video;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Resource;
use Workbench\App\Models\JsonSample;

/**
 * Витрина «Разметка и JSON»: многоязычное поле (`translatable()`), визуальный редактор RichText, Markdown,
 * Repeater с вложенным Repeater, пары KeyValue, конструктор Blocks из пяти типов блоков (в одном — список) и Seo.
 */
final class JsonSampleResource extends Resource
{
    protected static string $model = JsonSample::class;

    protected static ?string $label = 'Разметка и JSON';

    protected static ?string $singular = 'Пример разметки и JSON';

    protected static ?string $description = 'Длинные тексты и поля, которые хранят список в одной JSON-колонке.';

    protected static string $icon = 'braces';

    protected static ?string $group = 'Витрина: поля';

    protected static int $position = 40;

    /**
     * Поля по вкладкам: тексты, списки, конструктор, SEO.
     */
    public function fields(): array
    {
        return [
            Tabs::make([
                Tab::make('Тексты', [
                    Text::make('title', 'Название')->required()->searchable()->sortable(),
                    Text::make('subtitle', 'Подзаголовок')->translatable()->help('translatable() — вкладки языков fastleo.locales (ru, en), json-колонка'),
                    RichText::make('content', 'Текст')->height(320)->help('RichText: визуальный редактор, HTML чистится; height(320)'),
                    Markdown::make('notes', 'Заметки (Markdown)')->help('Markdown: текст с просмотром'),
                ]),
                Tab::make('Списки', [
                    Repeater::make('plans', 'Тарифы')
                        ->fields([
                            Text::make('title', 'Тариф')->required(),
                            Repeater::make('items', 'Что входит')
                                ->fields([
                                    Grid::make(2, [
                                        Text::make('name', 'Пункт')->required(),
                                        Number::make('price', 'Цена, ₽')->min(0),
                                    ]),
                                ])
                                ->max(10),
                        ])
                        ->max(6)
                        ->help('Repeater с вложенным Repeater (два уровня), max(6) и max(10)'),
                    KeyValue::make('specs', 'Характеристики')->keyLabel('Параметр')->valueLabel('Значение')->max(20)
                        ->help('KeyValue: keyLabel(), valueLabel(), max(20); колонка text — порядок строк сохраняется в MySQL'),
                ]),
                Tab::make('Конструктор', [
                    Blocks::make('body', 'Содержимое страницы')->max(10)->blocks([
                        Block::make('text', 'Текст')->icon('text')->fields([
                            RichText::make('body', 'Текст')->required(),
                        ]),
                        Block::make('image', 'Картинка')->icon('image')->fields([
                            Image::make('src', 'Картинка')->directory('showcase')->variants('card')->required(),
                            Text::make('caption', 'Подпись'),
                        ]),
                        Block::make('quote', 'Цитата')->icon('quote')->fields([
                            Textarea::make('text', 'Цитата')->required(),
                            Text::make('author', 'Автор'),
                        ]),
                        Block::make('features', 'Преимущества')->icon('list')->fields([
                            Text::make('heading', 'Заголовок'),
                            Repeater::make('items', 'Пункты')->fields([
                                Text::make('title', 'Пункт')->required(),
                                Textarea::make('text', 'Пояснение'),
                            ])->max(5),
                        ]),
                        Block::make('video', 'Видео')->icon('video')->fields([
                            Video::make('url', 'Ссылка на видео')->required(),
                        ]),
                    ])->help('Blocks: пять типов блоков, max(10); в «Преимуществах» — список Repeater'),
                ]),
                Tab::make('SEO', [
                    Seo::make('seo')->directory('showcase'),
                ]),
            ]),
        ];
    }
}
