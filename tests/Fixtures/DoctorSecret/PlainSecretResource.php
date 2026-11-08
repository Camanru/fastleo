<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorSecret;

use Fastleo\Fields\Secret;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Article;

/**
 * Секрет над колонкой без шифрования: значение лежало бы в базе открытым текстом.
 */
final class PlainSecretResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'plain-secret';

    /**
     * Название и секрет в колонке без каста `encrypted`.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
            Secret::make('excerpt'),
        ];
    }
}
