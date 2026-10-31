<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\DateTime;
use Fastleo\Fields\Email;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;
use Workbench\App\Models\Feedback;

/**
 * Раздел «Обращения» демо-сайта: только просмотр, новые сверху, счётчик в меню.
 */
final class FeedbackResource extends Resource
{
    protected static string $model = Feedback::class;

    protected static ?string $label = 'Обращения';

    protected static ?string $singular = 'Обращение';

    protected static string $icon = 'inbox';

    protected static ?string $slug = 'feedback';

    protected static bool $readonly = true;

    /** Новые обращения выделены в списке, число в меню — непросмотренные. */
    protected static ?string $readColumn = 'read_at';

    /**
     * Поля обращения.
     */
    public function fields(): array
    {
        return [
            Text::make('name', 'Имя')->searchable(),
            Email::make('email', 'Email')->searchable(),
            Textarea::make('message', 'Сообщение')->showInList(),
            DateTime::make('created_at', 'Получено')->onlyInList()->sortable(),
        ];
    }
}
