<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorLimits;

use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Статьи с множественным списком на 1100 вариантов: все они могут прийти в форме отдельными полями.
 */
final class WideSelectResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'wide-select';

    /**
     * Название и список из 1100 вариантов.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Select::make('excerpt')->multiple()->options(array_combine(range(1, 1100), range(1, 1100))),
        ];
    }
}
