<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\PageFactory;

/**
 * Страница демо-сайта с JSON-полями.
 *
 * @property int $id
 * @property array<string, string> $title
 * @property string $slug
 * @property list<array<string, mixed>>|null $faq
 * @property array<string, string|null>|null $specs
 * @property list<array{type: string, data: array<string, mixed>}>|null $content
 */
#[Fillable(['title', 'slug', 'faq', 'specs', 'content'])]
final class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /**
     * Фабрика страниц.
     */
    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'array',
            'faq' => 'array',
            'specs' => 'array',
            'content' => 'array',
        ];
    }
}
