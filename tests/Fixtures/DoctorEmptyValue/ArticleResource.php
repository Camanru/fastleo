<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorEmptyValue;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DoctorArticle;

/**
 * Статья, где NOT NULL-колонка `title` заполняется пустой строкой через emptyValue().
 */
final class ArticleResource extends Resource
{
    protected static string $model = DoctorArticle::class;

    protected static ?string $slug = 'empty-title';

    /**
     * Название не обязательное, но пустое значение определено.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->emptyValue(''),
        ];
    }
}
