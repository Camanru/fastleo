<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Symfony\Component\Process\Process;

/**
 * Запускает команду artisan сайта отдельным процессом PHP.
 *
 * Нужна установщику: когда он только что дописал модель пользователя, класс модели в его процессе уже загружен
 * в прежнем виде, а новый процесс прочитает файл заново. Аргументы — в формате `Artisan::call()`:
 * `['email' => 'a@b.ru', '--name' => 'Иван']`.
 *
 * ```php
 * app(ChildArtisan::class)->run('fastleo:doctor');   // код выхода команды
 * ```
 */
class ChildArtisan
{
    /**
     * Выполнить команду. С `$interactive` процесс получает терминал (вопросы команды видны и отвечаются как обычно),
     * без него вывод передаётся в `$output`, а вопросов нет (`--no-interaction`).
     *
     * @param  array<string, string|bool|null>  $arguments
     * @param  (callable(string): void)|null  $output
     */
    public function run(string $command, array $arguments = [], bool $interactive = false, ?callable $output = null): int
    {
        $line = [PHP_BINARY, base_path('artisan'), $command];

        foreach ($arguments as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $line[] = str_starts_with($name, '--') ? ($value === true ? $name : $name.'='.$value) : (string) $value;
        }

        $tty = $interactive && Process::isTtySupported();

        if (! $interactive) {
            $line[] = '--no-interaction';
        }

        $process = new Process($line, base_path());
        $process->setTimeout(null);
        $process->setTty($tty);

        return $process->run($tty || $output === null ? null : fn (string $type, string $buffer) => $output($buffer));
    }
}
