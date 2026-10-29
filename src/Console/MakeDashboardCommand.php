<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать свою главную страницу админки (класс Dashboard)
 *
 * ```bash
 * php artisan fastleo:make-dashboard
 * ```
 */
#[AsCommand(name: 'fastleo:make-dashboard')]
#[Description('Создать свою главную страницу админки (класс Dashboard с набором виджетов)')]
#[Signature('fastleo:make-dashboard {--force : перезаписать существующий файл}')]
final class MakeDashboardCommand extends Command
{
    /**
     * Создать класс `Dashboard` — имя фиксированное: пакет ищет именно его.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: '', stub: 'dashboard', suffix: '', view: null, nextKey: 'make_next_dashboard', name: 'Dashboard');
    }
}
