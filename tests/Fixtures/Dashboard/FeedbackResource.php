<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Dashboard;

use Fastleo\Fields\Email;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Feedback;

/**
 * Раздел обращений для таблицы на дашборде тестов.
 */
final class FeedbackResource extends Resource
{
    protected static string $model = Feedback::class;

    protected static ?string $slug = 'feedback';

    protected static bool $readonly = true;

    /**
     * Имя и email.
     */
    public function fields(): array
    {
        return [Text::make('name', 'Имя'), Email::make('email', 'Email')];
    }
}
