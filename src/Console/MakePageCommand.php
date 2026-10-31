<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать свою страницу админки: класс и шаблон или, с `--report`, отчёт из фильтров и виджетов без шаблона.
 *
 * ```bash
 * php artisan fastleo:make-page Instructions
 * php artisan fastleo:make-page LeadsReport --report
 * ```
 */
#[AsCommand(name: 'fastleo:make-page')]
#[Description('Создать свою страницу админки (класс и шаблон; --report — отчёт из фильтров и виджетов)')]
#[Signature('fastleo:make-page {name? : имя класса, например Report} {--report : отчёт: фильтры и виджеты, без своего шаблона} {--force : перезаписать существующие файлы}')]
final class MakePageCommand extends Command
{
    /**
     * Создать класс страницы и её шаблон (у отчёта шаблона нет).
     */
    public function handle(): int
    {
        $generator = new SiteClassGenerator($this, $this->components);

        if ($this->option('report') === true) {
            return $generator->generate(directory: '', stub: 'report-page', suffix: 'Page', view: null, nextKey: 'make_next_page');
        }

        return $generator->generate(directory: '', stub: 'page', suffix: 'Page', view: 'fastleo.{kebab}', nextKey: 'make_next_page');
    }
}
