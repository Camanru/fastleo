<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorSecret;

use Fastleo\Fields\Secret;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Supplier;

/**
 * Секрет над колонкой с кастом `encrypted`.
 */
final class EncryptedSecretResource extends Resource
{
    protected static string $model = Supplier::class;

    protected static ?string $slug = 'encrypted-secret';

    /**
     * Название и секрет в шифруемой колонке.
     */
    public function fields(): array
    {
        return [
            Text::make('name')->required(),
            Secret::make('api_key'),
        ];
    }
}
