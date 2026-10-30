<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DashboardLayout;

use Fastleo\Dashboard as BaseDashboard;
use Fastleo\Widgets\Stat;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;
use Workbench\App\Models\User;

/**
 * Дашборд тестов раскладки: размеры, одинаковые заголовки, свой ключ, виджет только для «Анны»,
 * счётчик подсчётов данных (скрытый виджет не должен считаться).
 */
final class Dashboard extends BaseDashboard
{
    /** Сколько раз посчитан виджет «Счётчик». */
    public static int $calls = 0;

    /**
     * Виджеты тестового дашборда.
     */
    #[Override]
    public function widgets(): array
    {
        return [
            Stat::make('Заявки')->value(1)->columnSpan(3)->rowSpan(2),
            Stat::make('Заявки')->value(2),
            Stat::make('Счётчик')->value(function (): int {
                self::$calls++;

                return 3;
            })->key('counter'),
            Stat::make('Только Анне')->value(4)->visible(fn (?Authenticatable $user): bool => $user instanceof User && $user->name === 'Анна'),
        ];
    }
}
