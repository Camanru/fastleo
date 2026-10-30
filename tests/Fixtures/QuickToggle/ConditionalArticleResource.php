<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Closure;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Переключатель поля, которое показывается только при выполненном условии и имеет своё правило.
 */
final class ConditionalArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'conditional-articles';

    /**
     * «Опубликовано» работает, пока статья «На главной»; значение «да» отклоняет собственное правило.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Toggle::make('is_featured', 'На главной'),
            Toggle::make('is_published', 'Опубликовано')
                ->visibleWhen('is_featured', true)
                ->rules(['boolean', fn (string $attribute, mixed $value, Closure $fail): mixed => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? $fail('Публиковать нельзя.') : null])
                ->quickToggle(),
        ];
    }
}
