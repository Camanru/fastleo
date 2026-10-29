<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать страницу настроек сайта
 *
 * ```bash
 * php artisan fastleo:make-settings Contacts
 * ```
 */
#[AsCommand(name: 'fastleo:make-settings')]
#[Description('Создать страницу настроек сайта (класс ContactsSettings)')]
#[Signature('fastleo:make-settings {name? : имя страницы, например Contacts} {--force : перезаписать существующие файлы}')]
final class MakeSettingsCommand extends Command
{
    /**
     * Создать класс страницы настроек.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: '', stub: 'settings', suffix: 'Settings', view: null, nextKey: 'make_next_settings');
    }
}
