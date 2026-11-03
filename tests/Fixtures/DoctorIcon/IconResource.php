<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorIcon;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DoctorArticle;

/**
 * Раздел с иконкой, которой нет в наборе Lucide.
 */
final class IconResource extends Resource
{
    protected static string $model = DoctorArticle::class;

    protected static ?string $slug = 'bad-icon';

    protected static string $icon = 'message-square-star';

    /**
     * Только название.
     */
    public function fields(): array
    {
        return [
            Text::make('title')->required(),
        ];
    }
}
