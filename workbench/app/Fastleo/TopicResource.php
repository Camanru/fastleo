<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Topic;

/**
 * Демо-раздел «Темы»: варианты для связи статей многие ко многим.
 */
final class TopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static ?string $label = 'Темы';

    protected static ?string $singular = 'Тема';

    protected static string $icon = 'tags';

    protected static ?string $group = 'Контент';

    protected static int $position = 30;

    protected static ?string $sortColumn = 'sort';

    /**
     * Название темы.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable()->sortable(),
        ];
    }
}
