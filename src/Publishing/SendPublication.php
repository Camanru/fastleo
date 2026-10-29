<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fastleo;
use Fastleo\Fields\DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * «Отправить» черновик или неудачную публикацию в выбранные каналы — сразу или в назначенное время (если очередь
 * умеет откладывать задачи): по доставке на канал, каждая — своей задачей `SendDelivery` (после фиксации
 * транзакции). Только человеком в админке, через MCP недоступно.
 */
final class SendPublication extends Action
{
    protected ?string $key = 'send';

    protected string $icon = 'send';

    protected bool $bulk = false;

    protected bool $mcp = false;

    /**
     * «Отправить в» — только если очередь умеет откладывать задачи; пусто — сразу.
     */
    #[Override]
    public function fields(): array
    {
        return SendDelivery::canDelay()
            ? [DateTime::make('send_at', __('fastleo::publishing.send_at'))->help(__('fastleo::publishing.send_at_help'))]
            : [];
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $publication = $records->first();

        if (! $publication instanceof Publication) {
            return ActionResult::error(__('fastleo::publishing.no_channels'));
        }

        if (SendDelivery::targetQueue() instanceof NullQueue) {
            return ActionResult::error(__('fastleo::publishing.queue_null'));
        }

        $at = is_string($data['send_at'] ?? null) ? Date::createFromFormat('Y-m-d H:i:s', $data['send_at'], Fastleo::appTimezone()) : null;

        if ($at !== null && $at->lte(now())) {
            return ActionResult::error(__('fastleo::publishing.time_passed'));
        }

        $channels = Channel::query()->where('enabled', true)->whereKey($publication->channel_ids ?? [])->pluck('id')->all();

        if ($channels === []) {
            return ActionResult::error(__('fastleo::publishing.no_channels'));
        }

        DB::transaction(function () use ($publication, $channels, $at): void {
            $publication->deliveries()->whereNotIn('channel_id', $channels)->delete();

            foreach ($channels as $channelId) {
                $delivery = $publication->deliveries()->firstOrNew(['channel_id' => $channelId]);

                if (! in_array($delivery->status, ['sent', 'sending', 'unknown'], true)) {
                    $delivery->fill(['status' => 'pending', 'error' => null])->save();
                }
            }

            $publication->user_id ??= Fastleo::guard()->id() === null ? null : (string) Fastleo::guard()->id();

            if ($at !== null) {
                $publication->forceFill(['status' => 'scheduled', 'scheduled_at' => $at])->save();
                StartPublication::schedule($publication);
            } else {
                $publication->start();
            }
        });

        $publication->refresh();

        return match ($publication->status) {
            'scheduled' => ActionResult::success(__('fastleo::publishing.scheduled', ['at' => $publication->scheduled_at?->timezone(Fastleo::timezone())->format('d.m.Y H:i')])),
            'sent' => ActionResult::success(__('fastleo::publishing.sent')),
            'sending' => ActionResult::success(__('fastleo::publishing.queued')),
            default => ActionResult::error(__('fastleo::publishing.status_'.$publication->status)),
        };
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::publishing.send');
    }

    /**
     * Черновик или неудачная отправка с выбранными каналами.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Publication && $record->isEditable() && ($record->channel_ids ?? []) !== [];
    }
}
