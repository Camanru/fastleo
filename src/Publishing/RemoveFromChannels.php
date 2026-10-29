<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Messengers\MessengerFailed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * «Удалить из каналов»: пост удаляется из каналов, а публикация остаётся в журнале со статусом «Удалено из каналов»
 * (после этого её можно удалить и из журнала). Telegram удаляет только посты моложе 48 часов — для старых
 * будет понятная ошибка по каналу. Через MCP недоступно.
 */
final class RemoveFromChannels extends Action
{
    protected ?string $key = 'remove-channels';

    protected string $icon = 'trash-2';

    protected bool $bulk = false;

    protected bool $danger = true;

    protected bool $mcp = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $publication = $records->first();
        $failures = [];

        if ($publication instanceof Publication) {
            foreach ($publication->deliveries()->with('channel')->whereIn('status', ['sent', 'unknown'])->get() as $delivery) {
                try {
                    if (($delivery->message_ids ?? []) === []) {
                        throw new MessengerFailed(__('fastleo::messengers.post_unknown'));
                    }

                    $delivery->channel->bot()->deletePost($delivery->channel->chat_id, $delivery->message_ids ?? []);
                    $delivery->update(['status' => 'removed', 'error' => null]);
                } catch (MessengerFailed $exception) {
                    $delivery->update(['error' => $exception->getMessage()]);
                    $failures[] = $delivery->channel->name.': '.$exception->getMessage();
                }
            }

            $publication->refreshStatus();
        }

        return $failures === []
            ? ActionResult::success(__('fastleo::publishing.removed'))
            : ActionResult::error(__('fastleo::publishing.remove_failed', ['reason' => implode('; ', $failures)]));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::publishing.remove_channels');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::publishing.remove_confirm');
    }

    /**
     * Есть посты в каналах (или могли появиться).
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Publication && $record->deliveries->contains(fn (Delivery $delivery): bool => in_array($delivery->status, ['sent', 'unknown'], true));
    }
}
