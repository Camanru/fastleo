<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Page;
use Illuminate\Http\Request;
use Override;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Демо-страница админки: сколько статей в каждом статусе.
 */
final class ReportPage extends Page
{
    protected static ?string $label = 'Отчёт по статьям';

    protected static string $icon = 'chart-bar';

    protected static ?string $group = 'Контент';

    protected static int $position = 90;

    #[Override]
    public function view(): string
    {
        return 'fastleo.report';
    }

    /**
     * Статьи по статусам.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function data(Request $request): array
    {
        return [
            'rows' => collect(ArticleStatus::cases())->mapWithKeys(fn (ArticleStatus $status): array => [$status->label() => Article::query()->where('status', $status)->count()])->all(),
        ];
    }
}
