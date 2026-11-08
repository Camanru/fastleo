<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\MediaSampleFactory;

/**
 * Запись витрины «Файлы и медиа»: картинки с обрезкой и вариантами, галерея, файл и видео.
 *
 * @property int $id
 * @property string $title
 * @property string|null $cover
 * @property list<string>|null $gallery
 */
#[Fillable(['title', 'cover', 'cover_alt', 'photo', 'gallery', 'document', 'video'])]
final class MediaSample extends Model
{
    /** @use HasFactory<MediaSampleFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): MediaSampleFactory
    {
        return MediaSampleFactory::new();
    }

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['gallery' => 'array'];
    }
}
