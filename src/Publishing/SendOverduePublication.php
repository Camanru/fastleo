<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * «Отправить сейчас» у отложенной публикации, время которой прошло больше 5 минут назад, а она всё ждёт
 * (задача ожидания потерялась: очищена очередь, сбой сервера). Через MCP недоступно.
 */
final class SendOverduePublication extends Action
{
    protected ?string $key = 'send-now';

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
        $publication = $records->first();

        if ($publication instanceof Publication && $publication->isOverdue()) {
            $publication->start();
        }

        return ActionResult::success(__('fastleo::publishing.queued'));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::publishing.send_now');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::publishing.send_now_confirm');
    }

    /**
     * Только просроченная отложенная публикация.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Publication && $record->isOverdue();
    }
}
