<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Number;
use Fastleo\Fields\Password;
use Fastleo\Fields\Secret;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Related;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Supplier;

/**
 * Раздел «Поставщики»: данные поставщика лежат в своей таблице, а товар только ссылается на него. Здесь же поля
 * `Password` и `Secret`, пустые столбцы (`hideWhenEmpty`) и таблица товаров поставщика на его странице.
 */
final class SupplierResource extends Resource
{
    protected static string $model = Supplier::class;

    protected static ?string $label = 'Поставщики';

    protected static ?string $singular = 'Поставщик';

    protected static string $icon = 'truck';

    protected static ?string $group = 'Каталог';

    protected static int $position = 15;

    /**
     * Число товаров считается в запросе.
     */
    public function query(Builder $query): Builder
    {
        return $query->withCount('products');
    }

    /**
     * Поля поставщика.
     */
    public function fields(): array
    {
        return [
            Text::make('name', 'Название')->required()->searchable()->sortable(),
            Tel::make('phone', 'Телефон')->hideWhenEmpty(),
            Text::make('note', 'Примечание')->hideWhenEmpty(),
            Number::make('products_count', 'Товаров')->fromQuery()->sortable(),
            Password::make('portal_password', 'Пароль личного кабинета')->hideOnEdit(),
            Secret::make('api_key', 'Ключ API'),
        ];
    }

    /**
     * Товары поставщика таблицей на его странице.
     */
    public function relations(): array
    {
        return [Related::make('products', ProductResource::class)->label('Товары поставщика')->perPage(10)];
    }
}
