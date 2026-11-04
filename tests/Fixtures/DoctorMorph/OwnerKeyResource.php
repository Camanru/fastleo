<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorMorph;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\OwnerKeyRemark;

/**
 * Заметка со связью по своему ключу.
 */
final class OwnerKeyResource extends Resource
{
    protected static string $model = OwnerKeyRemark::class;

    protected static ?string $slug = 'owner-key';

    /**
     * Текст и объект.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка')->required(), MorphTo::make('subject')->types([ArticleResource::class])];
    }
}
