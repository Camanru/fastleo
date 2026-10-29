<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;
use Workbench\App\Support\DocsBuilder;

/**
 * Пересобирает skill, гайдлайны Boost и `docs/llms.txt` из `docs/` (`composer docs:build`).
 */
#[AsCommand(name: 'fastleo:docs-build')]
#[Description('Собрать skill, гайдлайны Boost и llms.txt из docs/')]
#[Signature('fastleo:docs-build')]
final class BuildDocsCommand extends Command
{
    /**
     * Записать собранные файлы в пакет.
     */
    public function handle(): int
    {
        $root = dirname(__DIR__, 3);

        foreach (new DocsBuilder($root)->build() as $path => $content) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            File::put($root.'/'.$path, $content);
            $this->components->info($path);
        }

        return self::SUCCESS;
    }
}
