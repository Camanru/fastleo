<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\MorphLost;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Remark;

/**
 * Заметки, у поля которых не заданы разделы: описание поля с ошибкой.
 */
final class RemarkResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $slug = 'remarks';

    /**
     * Текст и объект без разделов.
     */
    public function fields(): array
    {
        return [Text::make('body', 'Заметка'), MorphTo::make('subject', 'О чём')];
    }
}
