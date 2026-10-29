<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\Doctor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Проверяет сайт и описанные разделы: поле без колонки, поле вне `$fillable`, связь без метода,
 * нет каста массива или enum, NOT NULL без `required()`, не выполнены миграции, не опубликованы ассеты и другое.
 * Код выхода 1 — есть ошибки, 0 — ошибок нет (предупреждения код не меняют).
 *
 * ```bash
 * php artisan fastleo:doctor
 * php artisan fastleo:doctor --json
 * ```
 */
#[AsCommand(name: 'fastleo:doctor')]
#[Description('Проверить сайт и разделы админки на ошибки описания (--json — для Claude)')]
#[Signature('fastleo:doctor {--json : вывести JSON}')]
final class DoctorCommand extends Command
{
    /**
     * Выполнить проверки и вывести результат.
     */
    public function handle(Doctor $doctor): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $problems = $doctor->run();
        $errors = count(array_filter($problems, fn (array $problem): bool => $problem['level'] === 'error'));
        $warnings = count($problems) - $errors;

        if ($this->option('json') === true) {
            $this->line(json_encode(['ok' => $errors === 0, 'errors' => $errors, 'warnings' => $warnings, 'problems' => $problems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $errors === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($problems as $problem) {
            $text = '['.$problem['scope'].'] '.$problem['message'].($problem['hint'] === null ? '' : ' → '.$problem['hint']);

            if ($problem['level'] === 'error') {
                $this->components->error($text);
            } else {
                $this->components->warn($text);
            }
        }

        if ($problems === []) {
            $this->components->info(__('fastleo::doctor.ok'));
        } else {
            $this->components->twoColumnDetail(__('fastleo::doctor.summary', ['errors' => $errors, 'warnings' => $warnings]));
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
