<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Messengers\MessengerFailed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * «Отправить тест» у бота уведомлений: проверочное сообщение во все его чаты сразу, минуя очередь.
 * Через MCP недоступно.
 */
final class SendTestMessage extends Action
{
    protected ?string $key = 'send-test';

    protected string $icon = 'send';

    protected bool $bulk = false;

    protected bool $mcp = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $bot = $records->first();

        if (! $bot instanceof NotificationBot) {
            return ActionResult::error(__('fastleo::notifications.test_failed', ['reason' => '—']));
        }

        $text = __('fastleo::notifications.test_text', ['site' => config()->string('app.name')]);
        $failures = [];

        foreach ($bot->recipients as $chatId) {
            try {
                $bot->bot()->sendText($chatId, $text);
            } catch (MessengerFailed $exception) {
                $failures[] = $chatId.': '.$exception->getMessage();
            }
        }

        return $failures === []
            ? ActionResult::success(__('fastleo::notifications.test_sent'))
            : ActionResult::error(__('fastleo::notifications.test_failed', ['reason' => implode('; ', $failures)]));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::notifications.test');
    }
}
