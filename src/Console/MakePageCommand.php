<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать свою страницу админки (класс и шаблон)
 *
 * ```bash
 * php artisan fastleo:make-page Report
 * ```
 */
#[AsCommand(name: 'fastleo:make-page')]
#[Description('Создать свою страницу админки (класс и шаблон)')]
#[Signature('fastleo:make-page {name? : имя класса, например Report} {--force : перезаписать существующие файлы}')]
final class MakePageCommand extends Command
{
    /**
     * Создать файлы класса и шаблона.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: '', stub: 'page', suffix: 'Page', view: 'fastleo.{kebab}', nextKey: 'make_next_page');
    }
}
