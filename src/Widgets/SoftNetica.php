<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Fastleo\Fastleo;
use Fastleo\SoftNetica\ClientData;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\SyncState;
use Override;

/**
 * Виджет SoftNetica: статус сайта, бэкап этой ночи, сроки SSL и домена, долг или ближайший платёж.
 * Виден только на подключённом сайте (токен введён и не отклонён). Во встроенном дашборде он уже есть;
 * в своём — добавьте `SoftNetica::make()`.
 *
 * ```php
 * public function widgets(): array
 * {
 *     return [Sections::make()->columnSpan(4), SoftNetica::make()->columnSpan(2)];
 * }
 * ```
 *
 * Данные приходят из последней синхронизации; старше 30 минут — пометка «нет связи с SoftNetica».
 */
final class SoftNetica extends Widget
{
    /**
     * Виден администратору, если сайт подключён к SoftNetica.
     */
    #[Override]
    public function isVisible(): bool
    {
        return Connection::connected() && parent::isVisible();
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.softnetica';
    }

    #[Override]
    protected function defaultTitle(): string
    {
        return __('fastleo::softnetica.title', [], Fastleo::LOCALE);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $state = resolve(SyncState::class);
        $client = $state->client();

        return [
            'data' => $client === null ? null : ClientData::from($client),
            'stale' => $state->isStale(),
            'syncedAt' => $state->syncedAt(),
            'pageUrl' => route('fastleo.pages.show', 'softnetica'),
        ];
    }
}
