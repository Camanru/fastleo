<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Fastleo\Fastleo;
use Fastleo\Health\HealthChecks;
use Fastleo\Support\CacheCleaner;
use Fastleo\Support\ServerReport;
use Override;

/**
 * Состояние сайта и сервера: версии, настройки, лимиты PHP, место на диске и свои проверки сайта (`Fastleo::healthCheck()`). У каждого замечания — причина и команда
 * для исправления. Кнопка «Очистить кеш» показывает, сколько места освободит.
 */
final class ServerStatus extends Widget
{
    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.server-status';
    }

    #[Override]
    protected function defaultTitle(): string
    {
        return __('fastleo::dashboard.server.title', [], Fastleo::LOCALE);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $sections = ServerReport::sections();
        $custom = $this->customChecks();

        if ($custom !== []) {
            $sections[] = ['title' => __('fastleo::dashboard.server.custom_checks', [], Fastleo::LOCALE), 'rows' => $custom];
        }

        return [
            'sections' => $sections,
            'attention' => ServerReport::attention($sections),
            'cacheSize' => CacheCleaner::format(CacheCleaner::size()),
        ];
    }

    /**
     * Свои проверки сайта (`Fastleo::healthCheck()`) в виде строк виджета: сбой — `danger`, текст результата — значение.
     *
     * @return list<array{key: string, title: string, value: string, status: ?string, hint: ?string}>
     */
    private function customChecks(): array
    {
        return array_map(function (array $check): array {
            $status = match ($check['status']) {
                'failed' => 'danger',
                'warning' => 'warning',
                default => 'ok',
            };

            return [
                'key' => $check['name'],
                'title' => $check['label'],
                'value' => $check['summary'] ?? __('fastleo::dashboard.server.check_'.$check['status'], [], Fastleo::LOCALE),
                'status' => $status,
                'hint' => $check['message'] ?? null,
            ];
        }, resolve(HealthChecks::class)->custom(mask: false));
    }
}
