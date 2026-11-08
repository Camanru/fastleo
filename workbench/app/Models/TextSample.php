<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\TextSampleFactory;

/**
 * Запись витрины «Текст и числа»: строки, числа, контакты, пароль, секрет, код и своё поле.
 *
 * @property int $id
 * @property string $title
 * @property int $quantity
 * @property string|null $price
 * @property string|null $promo_code
 * @property string|null $api_key
 */
#[Fillable(['title', 'slug', 'subtitle', 'sku', 'internal_code', 'note', 'description', 'quantity', 'price', 'rating', 'email', 'phone', 'website', 'password', 'api_key', 'color', 'snippet', 'source', 'promo_code'])]
#[Hidden(['password', 'api_key'])]
final class TextSample extends Model
{
    /** @use HasFactory<TextSampleFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): TextSampleFactory
    {
        return TextSampleFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price' => 'decimal:2', 'rating' => 'integer', 'password' => 'hashed', 'api_key' => 'encrypted'];
    }
}
