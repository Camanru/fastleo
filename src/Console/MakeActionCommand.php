<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать действие над записями (класс в папке разделов сайта)
 *
 * ```bash
 * php artisan fastleo:make-action PublishDoctors
 * ```
 */
#[AsCommand(name: 'fastleo:make-action')]
#[Description('Создать действие над записями (класс в папке разделов сайта)')]
#[Signature('fastleo:make-action {name? : имя класса, например PublishDoctors} {--force : перезаписать существующие файлы}')]
final class MakeActionCommand extends Command
{
    /**
     * Создать файлы класса.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: 'Actions', stub: 'action', suffix: '', view: null, nextKey: 'make_next_action');
    }
}
