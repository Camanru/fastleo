<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\EmptyValues;

use Fastleo\Fields\HasMany;
use Fastleo\Fields\Number;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Url;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи, где «нет значения» хранится не как NULL: тип `none`, ноль просмотров, пустая строка в NOT NULL-колонке.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'empty-values';

    /**
     * Три поля с `emptyValue()` и обычная строка для сравнения.
     */
    public function fields(): array
    {
        return [
            RichText::make('title', 'Заголовок')->emptyValue(''),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция'])->placeholder('Без вида')->emptyValue('none'),
            Number::make('views', 'Просмотры')->emptyValue(0),
            Text::make('phone', 'Телефон'),
            HasMany::make('links', 'Ссылки')->fields([
                Text::make('title', 'Название')->emptyValue(''),
                Url::make('url', 'Адрес'),
            ]),
        ];
    }
}
