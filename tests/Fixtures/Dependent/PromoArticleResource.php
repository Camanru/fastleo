<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Dependent;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Url;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи с условиями показа и зависимыми списками — для тестов visibleWhen и dependsOn.
 */
final class PromoArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'promo-articles';

    /** Тест MCP работает с разделом, где есть телефон, — раздел открыт явно. */
    protected static bool $mcpPersonalData = true;

    /**
     * Поля с условиями и зависимостями.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция'])->default('promo')->required(),
            Text::make('phone', 'Телефон акции')->required()->visibleWhen('type', 'promo'),
            Checkbox::make('is_featured', 'На главной'),
            DateTime::make('starts_at', 'На главной с')->required()->visibleWhen('is_featured', true),
            Select::make('layout', 'Вид страницы')
                ->options(fn (?string $type): array => $type === 'promo' ? ['banner' => 'С баннером'] : ['wide' => 'Широкий', 'narrow' => 'Узкий'])
                ->dependsOn('type'),
            BelongsTo::make('category_id', 'Категория'),
            BelongsTo::make('subcategory_id', 'Подраздел')
                ->dependsOn('category_id', fn (Builder $query, mixed $category) => $query->where('parent_id', $category ?? 0)),
            Select::make('status', 'Статус')->options(ArticleStatus::class)->readonly(),
            Select::make('color', 'Тон')->options(fn (?string $status): array => $status === 'published' ? ['calm' => 'Спокойный'] : ['loud' => 'Громкий'])->dependsOn('status')->visibleWhen('type', ['news', 'promo'])->hideInList(),
            BelongsToMany::make('topics', 'Темы')
                ->dependsOn('type', fn (Builder $query, mixed $type) => $query->where('sort', $type === 'promo' ? 1 : 2)),
            HasMany::make('links', 'Ссылки')->fields([
                Text::make('title', 'Название')->required(),
                Url::make('url', 'Адрес')->required()->visibleWhen('title', 'Сайт'),
            ]),
        ];
    }
}
