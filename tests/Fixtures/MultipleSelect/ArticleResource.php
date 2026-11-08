<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\MultipleSelect;

use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с множественными списками-плашками: обязательный, зависимый от вида статьи и только для чтения.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'multiple-select';

    /**
     * Вид статьи управляет вариантами меток (пока вид не выбран, меток нет); ключевые слова показываются без права правки.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Select::make('type', 'Вид')->options(['news' => 'Новость', 'promo' => 'Акция']),
            Select::make('tags', 'Метки')->multiple()->required()->dependsOn('type')
                ->options(fn (?string $type): array => match ($type) {
                    'promo' => ['sale' => 'Скидки'],
                    'news' => ['news' => 'Новости', 'guide' => 'Советы'],
                    default => [],
                }),
            Select::make('keywords', 'Ключевые слова')->multiple()->readonly()->options(['new' => 'Новинка', 'hit' => 'Хит']),
        ];
    }
}
