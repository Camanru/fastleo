<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Image;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\NumberRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Related;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Product;

/**
 * Раздел «Товары»: все приёмы списка в одном месте — правка и переключатель в ячейке, обложка в заголовке, столбцы,
 * исчезающие пока пусты, вычисляемый столбец, цвет строки, автообновление и свой шаблон ячейки; вкладки «Основное» и «Отзывы» (таблица `Related` во вкладке); поставщик — связь на отдельный раздел.
 */
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $label = 'Товары';

    protected static ?string $singular = 'Товар';

    protected static string $icon = 'package';

    protected static ?string $group = 'Каталог';

    protected static int $position = 10;

    /** Список обновляется сам раз в 15 секунд. */
    protected static ?int $poll = 15;

    protected static ?array $perPageOptions = [10, 25, 50];

    protected static ?array $defaultOrder = ['title', 'asc'];

    /**
     * Отзывы считаются в запросе: столбец «Отзывов» — вычисляемый (`fromQuery()`).
     */
    public function query(Builder $query): Builder
    {
        return $query->withCount('reviews');
    }

    /**
     * Поля товара.
     */
    public function fields(): array
    {
        return [
            Tabs::make([
                Tab::make('Основное', [
                    Text::make('title', 'Название')->required()->searchable()->sortable(),
                    Image::make('photo', 'Фото')->directory('products')->inTitle(),
                    Text::make('sku', 'Артикул')->maxLength(40)->searchable()->sortable()->disabled()->hideOnCreate(),
                    BelongsTo::make('category_id', 'Категория'),
                    BelongsTo::make('supplier_id', 'Поставщик'),
                    Grid::make(2, [
                        Number::make('price', 'Цена')->min(0)->max(99999999.99)->step(0.01)->sortable()->inlineEditable()->formatUsing(fn (mixed $value): string => number_format(is_numeric($value) ? (float) $value : 0.0, 2, ',', ' ').' ₽'),
                        Number::make('stock', 'Остаток')->integer()->min(0)->max(1000000)->sortable()->listView('fastleo.stock'),
                    ]),
                    Select::make('status', 'Статус')->options(['active' => 'В продаже', 'draft' => 'Черновик', 'archived' => 'Архив'])->badges()->inlineEditable()->default('active'),
                    Toggle::make('is_visible', 'На сайте')->default(true)->quickToggle(),
                    Number::make('reviews_count', 'Отзывов')->fromQuery()->sortable(),
                    Text::make('note', 'Примечание')->hideWhenEmpty(),
                    DateTime::make('created_at', 'Добавлен')->readonly()->hideOnCreate()->hideInList(),
                ]),
                Tab::make('Комплект', [
                    HasMany::make('bundleItems', 'Состав комплекта')->orderColumn('sort')->max(20)->fields([
                        BelongsTo::make('item_id', 'Товар')->required(),
                        Number::make('qty', 'Количество')->integer()->min(1)->max(1000)->default(1),
                    ]),
                ]),
                Tab::make('Отзывы', [
                    Related::make('reviews', ProductReviewResource::class)->editIn('modal')->quickAdd(['author', 'rating'])->addLabel('Добавить отзыв'),
                ]),
            ]),
        ];
    }

    /**
     * Фильтры списка.
     */
    public function filters(): array
    {
        return [
            SelectFilter::make('status'),
            BooleanFilter::make('is_visible', 'На сайте'),
            NumberRangeFilter::make('price', 'Цена'),
            NumberRangeFilter::make('stock', 'Остаток'),
        ];
    }

    /**
     * Строка товара без остатка — красная, черновик — серая, архив — жёлтая.
     */
    public function rowTone(Model $record): ?string
    {
        return match (true) {
            $record->getAttribute('status') === 'archived' => 'warning',
            $record->getAttribute('status') === 'draft' => 'neutral',
            $record->getAttribute('stock') == 0 => 'danger',
            default => null,
        };
    }
}
