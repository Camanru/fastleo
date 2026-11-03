<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\Database\Factories\SupplierFactory;

/**
 * Поставщик демо-сайта: один поставщик — много товаров; пароль кабинета и ключ API — поля Password и Secret.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $note
 */
#[Fillable(['name', 'phone', 'note', 'portal_password', 'api_key'])]
#[Hidden(['portal_password', 'api_key'])]
final class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    /**
     * Касты: хеш пароля и зашифрованный ключ.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['portal_password' => 'hashed', 'api_key' => 'encrypted'];
    }

    /**
     * Товары поставщика.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
