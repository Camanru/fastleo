<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\Priority;
use Workbench\Database\Factories\ChoiceSampleFactory;

/**
 * Запись витрины «Выбор и даты»: списки, enum, кнопки, галочки, метки, даты и зависимые поля.
 *
 * @property int $id
 * @property string $title
 * @property list<string>|null $colors
 * @property Priority $priority
 * @property bool $is_active
 */
#[Fillable(['title', 'size', 'colors', 'stage', 'shelf', 'brand', 'model', 'priority', 'delivery', 'address', 'is_gift', 'gift_note', 'is_active', 'labels', 'available_on', 'starts_at', 'opens_at'])]
final class ChoiceSample extends Model
{
    /** @use HasFactory<ChoiceSampleFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): ChoiceSampleFactory
    {
        return ChoiceSampleFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['colors' => 'array', 'labels' => 'array', 'shelf' => 'integer', 'priority' => Priority::class, 'is_gift' => 'boolean', 'is_active' => 'boolean', 'available_on' => 'date', 'starts_at' => 'datetime'];
    }
}
