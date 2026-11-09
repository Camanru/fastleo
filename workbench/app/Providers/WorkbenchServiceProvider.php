<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Fastleo\Notifications\NotificationBotResource;
use Fastleo\Publishing\ChannelResource;
use Fastleo\Publishing\PublicationResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\BuildDocsCommand;
use Workbench\App\Models\Article;
use Workbench\App\Models\Task;
use Workbench\App\Observers\TaskObserver;
use Workbench\App\Policies\ArticlePolicy;
use Workbench\App\Policies\TaskPolicy;

/**
 * Настройки демо-сайта: ресурсы админки лежат в workbench/app/Fastleo, размеры вариантов картинок
 * и водяной знак, права на статьи — ArticlePolicy, на задачи витрины — TaskPolicy и TaskObserver.
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
            // Сначала разделы демо-сайта, затем витрины возможностей.
            'fastleo.menu.groups' => ['Контент', 'Каталог', 'Витрина: поля', 'Витрина: список и действия', 'Витрина: отчёты'],
            'fastleo.resources.path' => __DIR__.'/../Fastleo',
            'fastleo.resources.namespace' => 'Workbench\App\Fastleo',
            'fastleo.images.presets' => [
                'card' => ['widths' => [400, 800], 'ratio' => '16:9'],
                'photo' => ['widths' => [480, 1200], 'watermark' => true],
            ],
            'fastleo.images.watermark.path' => 'uploads/watermark.png',
            // Демо показывает всё: колокольчик, боты уведомлений и публикации выключены у пакета по умолчанию.
            'fastleo.notifications.bell' => true,
            'fastleo.notifications.bots' => NotificationBotResource::class,
            'fastleo.publishing.channels' => ChannelResource::class,
            'fastleo.publishing.publications' => PublicationResource::class,
        ]);
    }

    /**
     * Policy и observers моделей демо-сайта.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BuildDocsCommand::class]);
        }

        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Task::observe(TaskObserver::class);
    }
}
