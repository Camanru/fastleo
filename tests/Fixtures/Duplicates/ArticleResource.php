<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Duplicates;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

/**
 * Статьи с уникальным заголовком: при дубле форма показывает ссылку на существующую статью.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'duplicate-articles';

    /** Раздел показывает только статьи с заголовком на «Моя» (как «только свои записи»). */
    public static bool $scoped = false;

    /** `duplicateOf()` возвращает рубрику с таким названием вместо статьи (модель другого раздела). */
    public static bool $foreign = false;

    /**
     * Ограничение раздела: при `$scoped` — только «свои» статьи.
     */
    public function query(Builder $query): Builder
    {
        return self::$scoped ? $query->where('title', 'like', 'Моя%') : $query;
    }

    /**
     * Заголовок с проверкой уникальности и поиском дубля.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()
                ->rules(fn (?Article $record): array => [Rule::unique('articles', 'title')->ignore($record)])
                ->duplicateOf(fn (string $title) => self::$foreign ? Category::query()->where('title', $title)->first() : Article::query()->where('title', $title)->first()),
        ];
    }
}
