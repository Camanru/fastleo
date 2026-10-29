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
 * «Обновить в каналах»: текущие текст и кнопка публикации уходят в уже отправленные посты (картинки не меняются).
 * Выполняется сразу, по каналу — ответ мессенджера. Через MCP недоступно.
 */
final class UpdateInChannels extends Action
{
    protected ?string $key = 'update-channels';

    protected string $icon = 'refresh-cw';

    protected bool $bulk = false;

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
            $html = $publication->html();

            foreach ($publication->deliveries()->with('channel')->where('status', 'sent')->get() as $delivery) {
                try {
                    $delivery->channel->bot()->editPost($delivery->channel->chat_id, (string) $delivery->layout, $delivery->message_ids ?? [], $delivery->media_tokens ?? [], $html, $publication->button());
                } catch (MessengerFailed $exception) {
                    $failures[] = $delivery->channel->name.': '.$exception->getMessage();
                }
            }
        }

        return $failures === []
            ? ActionResult::success(__('fastleo::publishing.updated'))
            : ActionResult::error(__('fastleo::publishing.update_failed', ['reason' => implode('; ', $failures)]));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::publishing.update_channels');
    }

    /**
     * Есть отправленные посты.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Publication && $record->deliveries->contains('status', 'sent');
    }
}
