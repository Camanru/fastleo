<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Доставки публикации на странице просмотра: канал, статус, когда ушло, ошибка. В форме и списке не показывается.
 */
final class PublicationDeliveries extends Field
{
    /** В списке не показывается. */
    protected bool $showInList = false;

    #[Override]
    public function isVisibleInForm(?Model $record): bool
    {
        return false;
    }

    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    #[Override]
    public function isExportable(): bool
    {
        return false;
    }

    /**
     * Список доставок; пусто — ничего.
     */
    #[Override]
    public function display(Model $record): ?Htmlable
    {
        if (! $record instanceof Publication) {
            return null;
        }

        $items = $record->deliveries()->with('channel')->orderBy('id')->get()->map(function (Delivery $delivery): string {
            $line = e($delivery->channel->name.' ('.$delivery->channel->messenger->label().')').' — <b>'.e(__('fastleo::publishing.delivery_'.$delivery->status, [], Fastleo::LOCALE)).'</b>';

            if ($delivery->sent_at !== null) {
                $line .= ', '.e($delivery->sent_at->timezone(Fastleo::timezone())->format('d.m.Y H:i'));
            }

            if (is_string($delivery->error) && $delivery->error !== '') {
                $line .= '<br><span class="text-danger">'.e($delivery->error).'</span>';
            }

            return '<li>'.$line.'</li>';
        });

        return $items->isEmpty() ? null : new HtmlString('<ul class="space-y-1.5" data-deliveries>'.$items->implode('').'</ul>');
    }
}
