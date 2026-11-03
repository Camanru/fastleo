<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RelatedTab;

use Fastleo\Fields\Text;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики, у которых таблица статей лежит во вкладке формы. Режим `$mode` ломает описание разными способами.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /** `ok`, `aside` (таблица во вкладке в боковой колонке), `nested` (вкладки в секции), `duplicate` (и в `relations()`), `twice` (таблица в двух вкладках). */
    public static string $mode = 'ok';

    /** Место правки таблицы статей: `page` или `modal`. */
    public static string $editIn = 'page';

    /**
     * Вкладки «Основное» (поля) и «Статьи» (таблица).
     */
    public function fields(): array
    {
        $articles = Related::make('articles', ArticleResource::class)->label('Статьи рубрики')->perPage(2)->editIn(self::$editIn);
        $tabs = Tabs::make([
            Tab::make('Основное', [Text::make('title', 'Название')->required(), Text::make('code', 'Код')->maxLength(50)->hideInList()]),
            Tab::make('Статьи', [self::$mode === 'aside' ? $articles->aside() : $articles]),
            ...(self::$mode === 'twice' ? [Tab::make('Ещё статьи', [clone $articles])] : []),
        ]);

        return [self::$mode === 'nested' ? Section::make('Секция', [$tabs]) : $tabs];
    }

    /**
     * Дубль таблицы статей только в режиме `duplicate`.
     */
    public function relations(): array
    {
        return self::$mode === 'duplicate' ? [Related::make('articles', ArticleResource::class)] : [];
    }
}
