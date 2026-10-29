<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * «Повторить» отправку в каналы с ошибкой, с «могло дойти» и с зависшей отправкой. Повтор продолжает
 * с места сбоя: уже отправленные части поста не дублируются. Через MCP недоступно.
 */
final class RetryPublication extends Action
{
    protected ?string $key = 'retry';

    protected string $icon = 'rotate-cw';

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
        $retried = 0;

        if ($publication instanceof Publication) {
            foreach ($publication->deliveries as $delivery) {
                if ($delivery->isRetryable()) {
                    $delivery->update(['status' => 'pending', 'error' => null]);
                    dispatch(new SendDelivery($delivery))->afterCommit();
                    $retried++;
                }
            }

            $publication->refreshStatus();
        }

        return ActionResult::success(__('fastleo::publishing.retried', ['count' => $retried]));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::publishing.retry');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::publishing.retry_confirm');
    }

    /**
     * Есть что повторить.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Publication && $record->deliveries->contains(fn (Delivery $delivery): bool => $delivery->isRetryable());
    }
}
