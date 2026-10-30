<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\Doctor;
use Fastleo\Support\Html\SanitizeDiff;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Проверяет сайт и описанные разделы: поле без колонки, поле вне `$fillable`, связь без метода,
 * нет каста массива или enum, NOT NULL без `required()`, не выполнены миграции, не опубликованы ассеты и другое.
 * Код выхода 1 — есть ошибки, 0 — ошибок нет (предупреждения код не меняют).
 *
 * ```bash
 * php artisan fastleo:doctor
 * php artisan fastleo:doctor --json
 * php artisan fastleo:doctor --sanitize-diff   # какие записи изменит чистка HTML редактора
 * ```
 */
#[AsCommand(name: 'fastleo:doctor')]
#[Description('Проверить сайт и разделы админки на ошибки описания (--json — для Claude)')]
#[Signature('fastleo:doctor {--json : вывести JSON} {--sanitize-diff : показать записи, которые изменит чистка HTML полей RichText}')]
final class DoctorCommand extends Command
{
    /**
     * Выполнить проверки и вывести результат.
     */
    public function handle(Doctor $doctor): int
    {
        app()->setLocale(Fastleo::LOCALE);

        if ($this->option('sanitize-diff') === true) {
            return $this->sanitizeDiff();
        }

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

    /**
     * Показать записи, которые изменит чистка HTML: по полю — число записей и первые расхождения «было → станет».
     */
    private function sanitizeDiff(): int
    {
        try {
            $report = new SanitizeDiff()->run();
        } catch (LogicException $e) {
            $this->components->error(__('fastleo::doctor.rich_text_config', ['message' => $e->getMessage()]));

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode(['fields' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($report as $row) {
            $this->components->warn(__('fastleo::doctor.sanitize_diff_field', ['resource' => $row['resource'], 'field' => $row['field'], 'changed' => $row['changed'], 'checked' => $row['checked']]));

            foreach ($row['records'] as $record) {
                $this->line('  #'.$record['key'].($record['locale'] === null ? '' : ' ['.$record['locale'].']'));
                $this->line('    '.__('fastleo::doctor.sanitize_diff_before').' '.$record['before']);
                $this->line('    '.__('fastleo::doctor.sanitize_diff_after').' '.$record['after']);
            }

            if ($row['changed'] > count($row['records'])) {
                $this->line('  '.__('fastleo::doctor.sanitize_diff_more', ['count' => $row['changed'] - count($row['records'])]));
            }
        }

        if ($report === []) {
            $this->components->info(__('fastleo::doctor.sanitize_diff_none'));
        }

        return self::SUCCESS;
    }
}
