<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SavedOptions;

use Fastleo\Fields\Radio;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с одиночным, множественным списком и Radio: в записях бывают значения, которых нет среди вариантов.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'saved-options';

    /**
     * Три вида списков с короткими наборами вариантов.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция']),
            Select::make('tags', 'Метки')->multiple()->options(['news' => 'Новости', 'promo' => 'Акции']),
            Radio::make('layout', 'Вид страницы')->options(['wide' => 'Широкий', 'narrow' => 'Узкий']),
        ];
    }
}
