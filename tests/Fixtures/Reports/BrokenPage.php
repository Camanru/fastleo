<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Reports;

use Fastleo\Page;
use Fastleo\Reports\Filters;
use Fastleo\Widgets\Rows;
use Fastleo\Widgets\Stat;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Override;
use RuntimeException;

/**
 * Отчёт, в котором один виджет падает, один скрыт, а таблица содержит разметку в значениях; доступ — по email.
 */
final class BrokenPage extends Page
{
    /**
     * Падающий виджет, скрытый виджет и таблица с чужой разметкой.
     */
    #[Override]
    public function widgets(Filters $filters): array
    {
        return [
            Stat::make('Падает')->value(fn (): int => throw new RuntimeException('нет данных')),
            Stat::make('Скрыт')->value(1)->visible(false),
            Stat::make('Работает')->value(7),
            Rows::make('Строки')->columns(['Название', 'Число'])->rows(fn (): array => [['<b>x</b>', 1234567], ['Лишнее', 1.5, 'не будет показано'], ['Целое', 2500.0], ['Объект', new RuntimeException]]),
        ];
    }

    /**
     * Страницу видит только владелец.
     */
    #[Override]
    public function canAccess(?Authenticatable $user): bool
    {
        return $user instanceof Model && $user->getAttribute('email') === 'owner@example.com';
    }
}
