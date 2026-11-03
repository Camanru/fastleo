<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorIcon;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DoctorArticle;

/**
 * Раздел, у которого иконка раздела верна, а иконка действия — нет.
 */
final class ActionIconResource extends Resource
{
    protected static string $model = DoctorArticle::class;

    protected static ?string $slug = 'bad-action-icon';

    /**
     * Название.
     */
    public function fields(): array
    {
        return [Text::make('title')->required()];
    }

    /**
     * Действие с неверной иконкой.
     */
    public function actions(): array
    {
        return [new BadIconAction];
    }
}
