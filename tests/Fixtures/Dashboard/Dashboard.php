<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Dashboard;

use Fastleo\Dashboard as BaseDashboard;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Stat;
use Fastleo\Widgets\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;
use RuntimeException;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Дашборд тестов: числа, график, таблица, упавший виджет и виджет только для «главного» админа.
 */
final class Dashboard extends BaseDashboard
{
    /**
     * Виджеты тестового дашборда.
     */
    #[Override]
    public function widgets(): array
    {
        return [
            Stat::make('Всего статей')->value(fn (): int => Article::query()->count())->description('на сайте')->icon('newspaper')->url('/fastleo/articles'),
            Stat::make('Опасная ссылка')->value('1 234')->url('javascript:alert(1)'),
            Stat::make('Чужой хост')->value(2)->url('//evil.example/a'),
            Stat::make('Обратная косая')->value(3)->url('/\\evil.example/b'),
            Stat::make('Сломанный')->value(fn () => throw new RuntimeException('boom')),
            Chart::make('Статьи по дням')->line()->data(fn (): array => Chart::countByDay(Article::query(), 'created_at', 7)),
            Chart::make('Пустой график')->bar()->data(fn (): array => []),
            Table::make('Последние обращения')->resource(FeedbackResource::class)->limit(2),
            Stat::make('Только владельцу')->value(7)->visible(fn (?Authenticatable $user): bool => $user instanceof User && $user->name === 'Анна'),
            Stat::make('Скрытый')->value(1)->visible(false),
        ];
    }
}
