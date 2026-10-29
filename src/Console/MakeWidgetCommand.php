<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать свой виджет дашборда (класс и шаблон)
 *
 * ```bash
 * php artisan fastleo:make-widget QueueSize
 * ```
 */
#[AsCommand(name: 'fastleo:make-widget')]
#[Description('Создать свой виджет дашборда (класс и шаблон)')]
#[Signature('fastleo:make-widget {name? : имя класса, например QueueSize} {--force : перезаписать существующие файлы}')]
final class MakeWidgetCommand extends Command
{
    /**
     * Создать файлы класса и шаблона.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: 'Widgets', stub: 'widget', suffix: '', view: 'fastleo.widgets.{kebab}', nextKey: 'make_next_widget');
    }
}
