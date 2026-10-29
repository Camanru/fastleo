<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fastleo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Secret;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Messengers\MatchesMessenger;
use Fastleo\Messengers\Messenger;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенный раздел «Каналы»: каналы Telegram и Max, куда публикуются записи сайта. Токен бота хранится
 * зашифрованным и не выводится; при сохранении бот проверяется в мессенджере (администратор канала с правами
 * публиковать, править и удалять).
 *
 * Включается ключом `fastleo.publishing.channels`: класс этого ресурса (по умолчанию), ваш наследник или
 * `null`, чтобы раздела не было. Через MCP недоступен.
 *
 * ```php
 * // config/fastleo.php: 'publishing' => ['channels' => App\Fastleo\MyChannels::class]
 * final class MyChannels extends \Fastleo\Publishing\ChannelResource
 * {
 *     protected static ?string $group = 'Публикации';
 * }
 * ```
 */
class ChannelResource extends Resource
{
    protected static string $model = Channel::class;

    protected static ?string $slug = 'channels';

    protected static string $icon = 'radio-tower';

    protected static int $position = 80;

    protected static ?array $defaultOrder = ['name', 'asc'];

    protected static bool $exportable = false;

    protected static bool $globallySearchable = false;

    protected static bool $mcp = false;

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::publishing.channels_label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::publishing.channels_singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::publishing.channels_description', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function group(): ?string
    {
        return static::$group ?? __('fastleo::users.group', [], Fastleo::LOCALE);
    }

    /**
     * Название, мессенджер, токен, номер канала, включён ли.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Text::make('name', __('fastleo::publishing.channel_name'))->maxLength(100)->sortable()->help(__('fastleo::publishing.channel_name_help')),
            Enum::make('messenger', __('fastleo::messengers.messenger'))->enum(Messenger::class)->required()->default(Messenger::Telegram->value)
                ->rules(fn (?Model $record): array => [Channel::keepsAfterDelivery($record, 'messenger')]),
            Secret::make('token', __('fastleo::messengers.token'))->required()->help(__('fastleo::messengers.token_help'))->rules(fn (?Model $record): array => [MatchesMessenger::token($record)]),
            ChannelChat::make('chat_id', __('fastleo::publishing.channel_chat'))->single()->required()->help(__('fastleo::publishing.channel_chat_help')),
            Toggle::make('enabled', __('fastleo::publishing.channel_enabled'))->default(true),
        ];
    }

    /**
     * Канал с публикациями удалить нельзя (журнал потерял бы, куда ушли посты) — его выключают.
     */
    #[Override]
    public function can(string $ability, ?Model $record): bool
    {
        if ($record instanceof Channel && in_array($ability, ['delete', 'forceDelete'], true)) {
            return ! $record->hasDeliveries();
        }

        return true;
    }
}
