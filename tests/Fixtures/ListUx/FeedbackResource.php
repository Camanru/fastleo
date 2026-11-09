<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ListUx;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Feedback;

/**
 * Обращения, которые открытие не помечает просмотренными: отметка меняется только действием.
 */
final class FeedbackResource extends Resource
{
    protected static string $model = Feedback::class;

    protected static ?string $slug = 'feedback';

    protected static ?string $readColumn = 'read_at';

    protected static bool $readOnOpen = false;

    /**
     * Имя автора.
     */
    public function fields(): array
    {
        return [Text::make('name', 'Имя')];
    }
}
