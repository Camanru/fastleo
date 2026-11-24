<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SearchUsingTranslatable;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Page;

/**
 * Страницы с многоязычным заголовком и своим условием поиска: запоминает, для каких колонок вызван хук.
 */
final class PageResource extends Resource
{
    protected static string $model = Page::class;

    /** @var list<string> */
    public static array $columns = [];

    protected static ?string $slug = 'site-pages';

    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->translatable()->searchUsing(function (Builder $query, string $term, string $column): void {
                self::$columns[] = $column;
                $query->where($column, 'like', '%'.$term.'%');
            }),
        ];
    }
}
