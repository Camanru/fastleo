<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Кладёт в сайт skill для Claude Code: порядок работы с разделами, правила, команды, список полей.
 * Файлы перезаписываются версией из установленного пакета — после `composer update` команду стоит повторить.
 *
 * ```bash
 * php artisan fastleo:skill
 * php artisan fastleo:skill --path=.cursor/skills/fastleo
 * ```
 */
#[AsCommand(name: 'fastleo:skill')]
#[Description('Положить в сайт skill fastleo для Claude Code (.claude/skills/fastleo)')]
#[Signature('fastleo:skill {--path= : папка для skill (по умолчанию .claude/skills/fastleo в корне сайта)}')]
final class SkillCommand extends Command
{
    /** Папка skill внутри пакета (её же читает Laravel Boost). */
    public const string SOURCE = 'resources/boost/skills/fastleo';

    /**
     * Скопировать skill.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $path = $this->option('path');
        $target = is_string($path) && $path !== '' ? $path : base_path('.claude/skills/fastleo');

        File::copyDirectory(dirname(__DIR__, 2).'/'.self::SOURCE, $target);
        $this->components->info(__('fastleo::console.skill_done', ['path' => $target]));

        return self::SUCCESS;
    }
}
