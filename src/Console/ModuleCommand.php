<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Installer\Module;
use Fastleo\Installer\ModuleInstaller;
use Fastleo\Installer\Modules;
use Fastleo\Installer\ModuleWriteException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * Добавляет в сайт готовые модули: код копируется в `app/`, `resources/`, `database/` и дальше принадлежит сайту.
 * Нужен Pest (`pestphp/pest` и `tests/Pest.php`): тесты модулей копируются вместе с ним; без Pest команда ничего не делает.
 * Если хоть один файл или таблица панельной части занят, не записывается ничего. После выбора модулей в терминале
 * задаётся один вопрос: ставить ли публичную часть сайта (маршруты, контроллеры, шаблоны); без терминала — ставится.
 *
 * ```bash
 * php artisan fastleo:module            # список с галочками, затем вопрос про публичную часть
 * php artisan fastleo:module contacts   # вопрос про публичную часть задаётся, если есть терминал
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

        if (! $installer->hasPest()) {
            $this->components->error(__('fastleo::console.module_pest_required'));

            return self::FAILURE;
        }

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

        $conflicts = $installer->conflicts($modules);

        if ($conflicts !== []) {
            $this->components->error(__('fastleo::console.module_conflicts'));
            $this->components->bulletList($conflicts);

            return self::FAILURE;
        }

        try {
            $written = $installer->install($modules, $this->wantsSite($installer, $modules));
        } catch (ModuleWriteException $e) {
            $this->components->error(__('fastleo::console.module_write_failed', ['message' => $e->getMessage()]));

            return self::FAILURE;
        }

        Fastleo::resources()->flush();
        $this->components->info(__('fastleo::console.module_created'));
        $this->components->bulletList($written);

        foreach ($installer->siteSkipped as $name => $reasons) {
            $this->components->warn(__('fastleo::console.module_site_skipped', ['module' => $name]));
            $this->components->bulletList($reasons);
        }

        foreach ($installer->warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($installer->notes as $note) {
            $this->components->info($note);
        }

        if ($this->option('without-hints') !== true && array_any($modules, fn (Module $module): bool => $module->tables !== [])) {
            $this->nextSteps($modules);
        }

        return self::SUCCESS;
    }

    /**
     * Ставить ли публичную часть сайта: вопрос после выбора модулей и до записи файлов. Без терминала ответ «да».
     * Если публичная часть занята у всех выбранных модулей, спрашивать не о чём: установка сама напечатает причины.
     *
     * @param  list<Module>  $modules
     */
    private function wantsSite(ModuleInstaller $installer, array $modules): bool
    {
        if (! $this->input->isInteractive() || count($installer->siteConflicts($modules)) === count($modules)) {
            return true;
        }

        return confirm(label: __('fastleo::console.module_site_ask'), default: true);
    }

    /**
     * Подсказка, что делать после копирования: миграции и, по желанию, сидеры с примерами данных.
     *
     * @param  list<Module>  $modules
     */
    private function nextSteps(array $modules): void
    {
        $this->components->info(__('fastleo::console.module_next'));
        $this->line('  '.__('fastleo::console.module_next_migrate'));

        $seeders = array_values(array_unique(array_merge(...array_map(fn (Module $module): array => $module->seeders, $modules))));

        if ($seeders !== []) {
            $this->line('  '.__('fastleo::console.module_next_seed', ['commands' => implode('; ', array_map(fn (string $seeder): string => "php artisan db:seed --class='{$seeder}'", $seeders))]));
        }

        $this->newLine();
    }
}
