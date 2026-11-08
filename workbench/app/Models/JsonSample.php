<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\JsonSampleFactory;

/**
 * Запись витрины «Разметка и JSON»: визуальный редактор, Markdown, повторитель, пары, конструктор блоков, SEO и перевод.
 *
 * @property int $id
 * @property string $title
 * @property array<string, string>|null $subtitle
 * @property list<array<string, mixed>>|null $plans
 * @property array<string, string>|null $specs
 * @property list<array{type: string, data: array<string, mixed>}>|null $body
 */
#[Fillable(['title', 'subtitle', 'content', 'notes', 'plans', 'specs', 'body', 'seo'])]
final class JsonSample extends Model
{
    /** @use HasFactory<JsonSampleFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): JsonSampleFactory
    {
        return JsonSampleFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['subtitle' => 'array', 'plans' => 'array', 'specs' => 'array', 'body' => 'array', 'seo' => 'array'];
    }
}
