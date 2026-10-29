<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Страница с методами spatie/laravel-translatable (без самого пакета): переводы в JSON-строке колонки.
 *
 * @property string $title
 */
#[Fillable(['title', 'slug'])]
#[Table(name: 'pages')]
final class SpatiePage extends Model
{
    /** @var list<string> */
    public array $translatable = ['title'];

    /**
     * Атрибут переводится.
     */
    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translatable, true);
    }

    /**
     * Все переводы атрибута.
     *
     * @return array<string, string>
     */
    public function getTranslations(string $key): array
    {
        $value = json_decode((string) ($this->attributes[$key] ?? ''), true);

        return is_array($value) ? $value : [];
    }

    /**
     * Заменить все переводы атрибута.
     *
     * @param  array<string, string>  $translations
     */
    public function replaceTranslations(string $key, array $translations): static
    {
        $this->attributes[$key] = json_encode($translations, JSON_UNESCAPED_UNICODE);

        return $this;
    }
}
