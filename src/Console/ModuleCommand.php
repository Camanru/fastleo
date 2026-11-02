<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Installer\Module;
use Fastleo\Installer\ModuleConflictException;
use Fastleo\Installer\ModuleInstaller;
use Fastleo\Installer\Modules;
use Fastleo\Installer\ModuleWriteException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\multiselect;

/**
 * Добавляет в сайт готовые модули: код копируется в `app/`, `resources/`, `database/` и дальше принадлежит сайту.
 * Если хоть один файл, таблица или адрес занят, не записывается ничего.
 *
 * ```bash
 * php artisan fastleo:module            # список с галочками
 * php artisan fastleo:module contacts   # без вопросов
 * ```
 */
#[AsCommand(name: 'fastleo:module')]
#[Description('Добавить в сайт готовые модули (код копируется в проект)')]
#[Signature('fastleo:module {modules?* : Имена модулей} {--without-hints : не напоминать про migrate (напоминание есть в итоге fastleo:install)}')]
final class ModuleCommand extends Command
{
    /**
     * Выбор модулей, проверка конфликтов и запись файлов.
     */
    public function handle(ModuleInstaller $installer): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $names = array_values(array_unique((array) $this->argument('modules')));

        if ($names === []) {
            if (! $this->input->isInteractive()) {
                $this->components->error(__('fastleo::console.module_none', ['modules' => implode(', ', array_keys(Modules::all()))]));

                return self::FAILURE;
            }

            $names = array_map(strval(...), array_values(multiselect(
                label: __('fastleo::console.module_choose'),
                options: array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all()),
                required: true,
            )));
        }

        $modules = [];

        foreach ($names as $name) {
            $module = Modules::find($name);

            if (! $module instanceof Module) {
                $this->components->error(__('fastleo::console.module_unknown', ['name' => $name, 'modules' => implode(', ', array_keys(Modules::all()))]));

                return self::FAILURE;
            }

            $modules[] = $module;
        }

        try {
            $written = $installer->install($modules);
        } catch (ModuleConflictException $e) {
            $this->components->error(__('fastleo::console.module_conflicts'));
            $this->components->bulletList($e->conflicts);

            return self::FAILURE;
        } catch (ModuleWriteException $e) {
            $this->components->error(__('fastleo::console.module_write_failed', ['message' => $e->getMessage()]));

            return self::FAILURE;
        }

        Fastleo::resources()->flush();
        $this->components->info(__('fastleo::console.module_created'));
        $this->components->bulletList($written);

        foreach ($installer->notes as $note) {
            $this->components->info($note);
        }

        if ($this->option('without-hints') !== true && array_any($modules, fn (Module $module): bool => $module->tables !== [])) {
            $this->components->warn(__('fastleo::console.module_migrate'));
        }

        if ($installer->testsSkipped) {
            $this->components->warn(__('fastleo::console.module_no_pest'));
        }

        return self::SUCCESS;
    }
}
