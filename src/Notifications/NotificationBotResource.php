<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Fastleo\Fastleo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Secret;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Messengers\ChatIds;
use Fastleo\Messengers\MatchesMessenger;
use Fastleo\Messengers\Messenger;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенный раздел «Уведомления»: боты Telegram и Max, через которые `Fastleo::notifyAdmins()` дублирует
 * уведомления колокольчика в чаты администраторов. Токен бота хранится зашифрованным и не выводится.
 *
 * Это служебные чаты админов, не каналы для подписчиков. Включается ключом `fastleo.notifications.bots`:
 * по умолчанию `null` — раздела нет; чтобы включить, укажите класс этого ресурса или ваш наследник. Через MCP недоступен.
 *
 * ```php
 * // config/fastleo.php: 'notifications' => ['bots' => App\Fastleo\BotResource::class]
 * final class BotResource extends \Fastleo\Notifications\NotificationBotResource
 * {
 *     protected static ?string $group = 'Настройки';
 * }
 * ```
 */
class NotificationBotResource extends Resource
{
    protected static string $model = NotificationBot::class;

    protected static ?string $slug = 'notification-bots';

    protected static string $icon = 'bell-ring';

    protected static int $position = 90;

    protected static ?array $defaultOrder = ['name', 'asc'];

    protected static bool $exportable = false;

    protected static bool $globallySearchable = false;

    protected static bool $mcp = false;

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::notifications.bots_label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::notifications.bots_singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::notifications.bots_description', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function group(): ?string
    {
        return static::$group ?? __('fastleo::users.group', [], Fastleo::LOCALE);
    }

    /**
     * Название, мессенджер, токен, чаты получателей, включён ли.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Text::make('name', __('fastleo::notifications.bot_name'))->required()->maxLength(100)->sortable(),
            Enum::make('messenger', __('fastleo::messengers.messenger'))->enum(Messenger::class)->required()->default(Messenger::Telegram->value),
            Secret::make('token', __('fastleo::messengers.token'))->required()->help(__('fastleo::messengers.token_help'))->rules(fn (?Model $record): array => [MatchesMessenger::token($record)]),
            ChatIds::make('recipients', __('fastleo::notifications.recipients'))->required()->help(__('fastleo::notifications.recipients_help'))->rules([MatchesMessenger::chats()]),
            Toggle::make('enabled', __('fastleo::notifications.bot_enabled'))->default(true),
        ];
    }

    #[Override]
    public function actions(): array
    {
        return [new SendTestMessage];
    }
}
