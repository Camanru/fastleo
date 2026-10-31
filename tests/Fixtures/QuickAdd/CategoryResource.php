<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickAdd;

use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Рубрики со связанной таблицей статей и формой быстрого добавления; какие поля в ней — задаёт `$fields`.
 */
final class CategoryResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'categories';

    /** @var list<string>|null Поля быстрого добавления; null — формы нет. */
    public static ?array $fields = ['title', 'views'];

    /**
     * Название рубрики.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }

    /**
     * Таблица статей рубрики с подписью кнопки и быстрым добавлением по `$fields`.
     */
    public function relations(): array
    {
        $related = Related::make('articles', ArticleResource::class)->addLabel('Добавить статью');

        return [self::$fields === null ? $related : $related->quickAdd(self::$fields)];
    }
}
