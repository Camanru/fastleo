<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Prefill;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Date;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Hidden;
use Fastleo\Fields\Number;
use Fastleo\Fields\Password;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи для проверки предзаполнения формы создания из адреса: простые поля, список, запись с ограничением,
 * поле только для чтения, пароль, галочка, теги и скрытое поле.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'prefill';

    /**
     * Поля разных видов.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->maxLength(20),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class),
            Number::make('views', 'Просмотры')->integer()->min(0),
            Date::make('published_on', 'Дата'),
            BelongsTo::make('category_id', 'Категория')->query(fn (Builder $query) => $query->whereNotNull('code')),
            Text::make('author_email', 'Только для чтения')->readonly(),
            Password::make('color', 'Пароль'),
            Toggle::make('is_featured', 'Важная'),
            Tags::make('keywords', 'Слова'),
            Hidden::make('type')->default('news'),
        ];
    }
}
