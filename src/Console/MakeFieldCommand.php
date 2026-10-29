<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Support\SiteClassGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Создать своё поле формы (класс и шаблон)
 *
 * ```bash
 * php artisan fastleo:make-field Phone
 * ```
 */
#[AsCommand(name: 'fastleo:make-field')]
#[Description('Создать своё поле формы (класс и шаблон)')]
#[Signature('fastleo:make-field {name? : имя класса, например Phone} {--force : перезаписать существующие файлы}')]
final class MakeFieldCommand extends Command
{
    /**
     * Создать файлы класса и шаблона.
     */
    public function handle(): int
    {
        return new SiteClassGenerator($this, $this->components)->generate(directory: 'Fields', stub: 'field', suffix: '', view: 'fastleo.fields.{kebab}', nextKey: 'make_next_field');
    }
}
