<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Morph;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Product;

/**
 * Товары, на которые можно сослаться из заметки.
 */
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $slug = 'products';

    protected static ?string $singular = 'Товар';

    /** Раздел закрыт методом `can()` (без Policy). */
    public static bool $closed = false;

    /**
     * Список раздела открыт, пока тест его не закрыл.
     */
    public function can(string $ability, ?Model $record): bool
    {
        return ! self::$closed || $ability !== 'viewAny';
    }

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }
}
