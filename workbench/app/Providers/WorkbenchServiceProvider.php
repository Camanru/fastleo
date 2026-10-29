<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\BuildDocsCommand;
use Workbench\App\Models\Article;
use Workbench\App\Policies\ArticlePolicy;

/**
 * Настройки демо-сайта: ресурсы админки лежат в workbench/app/Fastleo, размеры вариантов картинок
 * и водяной знак, права на статьи — ArticlePolicy.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Папка и namespace ресурсов демо-сайта.
     */
    #[\Override]
    public function register(): void
    {
        config([
            'fastleo.locales' => ['ru', 'en'],
            'fastleo.resources.path' => __DIR__.'/../Fastleo',
            'fastleo.resources.namespace' => 'Workbench\App\Fastleo',
            'fastleo.images.presets' => [
                'card' => ['widths' => [400, 800], 'ratio' => '16:9'],
                'photo' => ['widths' => [480, 1200], 'watermark' => true],
            ],
            'fastleo.images.watermark.path' => 'uploads/watermark.png',
        ]);
    }

    /**
     * Policy моделей демо-сайта.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BuildDocsCommand::class]);
        }

        Gate::policy(Article::class, ArticlePolicy::class);
    }
}
