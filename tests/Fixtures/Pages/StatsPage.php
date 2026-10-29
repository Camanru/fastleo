<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Pages;

use Fastleo\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Override;

/**
 * Страница тестов: показывает значение из запроса; доступна только администратору с id = 1.
 */
final class StatsPage extends Page
{
    protected static ?string $label = 'Статистика';

    protected static string $icon = 'chart-bar';

    protected static ?string $group = 'Отчёты';

    /**
     * Шаблон из папки тестов.
     */
    #[Override]
    public function view(): string
    {
        View::addNamespace('pages-fixture', __DIR__.'/views');

        return 'pages-fixture::stats';
    }

    /**
     * Значение параметра ?n.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function data(Request $request): array
    {
        return ['n' => $request->query('n', 'нет')];
    }
}
