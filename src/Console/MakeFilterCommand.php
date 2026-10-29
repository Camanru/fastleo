<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать свой фильтр списка (класс и шаблон)
 *
 * ```bash
 * php artisan fastleo:make-filter ByCity
 * ```
 */
#[AsCommand(name: 'fastleo:make-filter')]
#[Description('Создать свой фильтр списка (класс и шаблон)')]
#[Signature('fastleo:make-filter {name? : имя класса, например ByCity} {--force : перезаписать существующие файлы}')]
final class MakeFilterCommand extends Command
{
    /**
     * Создать файлы класса и шаблона.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: 'Filters', stub: 'filter', suffix: '', view: 'fastleo.filters.{kebab}', nextKey: 'make_next_filter');
    }
}
