<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DependentChain;

use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Цепочка из трёх зависимых списков: вид → вид страницы → тон. Смена первого сбрасывает второй, а тот — третий.
 */
final class ChainArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'chain-articles';

    /**
     * Название и три зависимых друг от друга списка.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция'])->default('promo')->required(),
            Select::make('layout', 'Вид страницы')
                ->options(fn (?string $type): array => $type === 'promo' ? ['banner' => 'С баннером'] : ['wide' => 'Широкий', 'narrow' => 'Узкий'])
                ->dependsOn('type'),
            Select::make('color', 'Тон')
                ->options(fn (?string $layout): array => $layout === 'banner' ? ['calm' => 'Спокойный'] : ['loud' => 'Громкий', 'quiet' => 'Тихий'])
                ->dependsOn('layout'),
        ];
    }
}
