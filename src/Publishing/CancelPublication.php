<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * «Отменить отправку» отложенной публикации: она снова черновик (можно поправить и отправить в другое время),
 * задача ожидания, проснувшись, ничего не сделает. Через MCP недоступно.
 */
final class CancelPublication extends Action
{
    protected ?string $key = 'cancel';

    protected string $icon = 'calendar-x';

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

        if ($publication instanceof Publication && $publication->status === 'scheduled') {
            $publication->deliveries()->where('status', 'pending')->delete();
            $publication->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();
            $publication->refreshStatus();
        }

        return ActionResult::success(__('fastleo::publishing.cancelled'));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::publishing.cancel');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::publishing.cancel_confirm');
    }

    /**
     * Только отложенная публикация.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Publication && $record->status === 'scheduled';
    }
}
