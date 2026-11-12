<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Installer\ChildArtisan;
use Fastleo\Installer\Module;
use Fastleo\Installer\ModuleInstaller;
use Fastleo\Installer\Modules;
use Fastleo\Support\Doctor;
use Fastleo\Support\UserModel;
use Fastleo\Support\UserModelPatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Первичная установка fastleo на сайт.
 *
 * Публикует конфиг (если его ещё нет) и ассеты, подключает модель пользователя (интерфейс FastleoUser и трейт
 * HasFastleoAccess), проверяет очередь, спрашивает, какие готовые модули добавить на сайт (вид установки → модули →
 * ставить ли публичную часть сайта: вопрос задаёт `fastleo:module`), и с подтверждения выполняет миграции, `storage:link` и создаёт пользователя. Без вопросов (`--no-interaction`)
 * ничего из этого не выполняется, пока не указаны флаги.
 *
 * ```bash
 * php artisan fastleo:install
 * php artisan fastleo:install --no-interaction --modules=pages,news --setup --admin-email=admin@site.ru --admin-password=secret123
 * ```
 */
#[AsCommand(name: 'fastleo:install')]
#[Description('Установить fastleo: конфиг, ассеты, skill для Claude, модель пользователя, готовые модули сайта, миграции и пользователь')]
#[Signature('fastleo:install
        {--skill-path= : папка для skill Claude (по умолчанию .claude/skills/fastleo)}
        {--type= : вид установки: basic (без модулей) или custom (с выбором модулей)}
        {--modules= : модули через запятую (contacts,pages,landing,news,menu,reviews,portfolio,faq,services,prices,team,branches,certificates,partners,shop) — без списка выбора (вопрос про публичную часть сайта в терминале остаётся)}
        {--no-modules : не добавлять модули}
        {--setup : выполнить миграции и storage:link без вопросов}
        {--seed : заполнить модули примерами}
        {--admin-email= : создать пользователя с этим email}
        {--admin-password= : пароль пользователя}')]
final class InstallCommand extends Command
{
    /**
     * Публикация, опрос, модули и шаги установки. Код выхода 1, если модель пользователя не готова и подключить её
     * автоматически не вышло, или модули поставить нельзя.
     */
    public function handle(ModuleInstaller $installer, ChildArtisan $artisan): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $this->callSilently('vendor:publish', ['--tag' => 'fastleo-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'fastleo-assets', '--force' => true]);
        $this->callSilently('fastleo:skill', ['--path' => $this->option('skill-path')]);
        $this->components->info(__('fastleo::console.install_published'));

        $result = self::SUCCESS;
        $modelConnected = false;

        if (UserModel::fastleoClassName() === null) {
            $model = UserModel::className();
            $file = $model !== null && is_a($model, Model::class, true) ? UserModelPatcher::connect($model) : null;

            if ($file !== null) {
                $modelConnected = true;
                $this->components->info(__('fastleo::console.install_model_connected', ['model' => $model, 'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file)]));
            } else {
                $this->components->error(__('fastleo::console.install_model_manual', ['model' => $model ?? '—']));
                $result = self::FAILURE;
            }
        }

        if (config('queue.default') === 'sync') {
            $this->components->warn(__('fastleo::console.install_queue_sync'));
        }

        $modules = $this->chooseModules($installer);

        if ($modules === null || ($modules !== [] && $this->call('fastleo:module', ['modules' => $modules, '--without-hints' => true]) !== self::SUCCESS)) {
            return self::FAILURE;
        }

        $steps = $this->runSteps($modules, $modelConnected ? $artisan : null);
        $migrates = array_any($modules, fn (string $name): bool => (Modules::find($name)->tables ?? []) !== []);

        $this->components->bulletList(array_values(array_filter([
            $steps['migrated'] || ! Doctor::packageMigrationsPending() && ! $migrates ? null : __('fastleo::console.install_step_migrate'),
            Doctor::storageLinkMissing() ? __('fastleo::console.install_step_storage') : null,
            $steps['admin'] ? null : __('fastleo::console.install_step_user'),
            __('fastleo::console.install_step_open', ['url' => route('fastleo.login')]),
        ])));

        return $steps['failed'] ? self::FAILURE : $result;
    }

    /**
     * Какие модули добавить: флаги `--no-modules`, `--modules`, `--type` или опрос (вид установки → список с галочками).
     * null — флаги заданы неверно.
     *
     * @return list<string>|null
     */
    private function chooseModules(ModuleInstaller $installer): ?array
    {
        $names = $this->option('modules');
        $listed = is_string($names) && $names !== '';
        $noModules = $this->option('no-modules') === true;

        // Противоречивые флаги молча не разрешаем: CI и Claude должны узнать, что установка не такая, как просили.
        if (($noModules && ($listed || $this->option('type') === 'custom')) || ($listed && $this->option('type') === 'basic')) {
            $this->components->error(__('fastleo::console.install_flags_conflict'));

            return null;
        }

        if ($noModules) {
            return [];
        }

        if ($listed) {
            if (! $this->pestInstalled($installer)) {
                return null;
            }

            $modules = array_values(array_filter(array_map(trim(...), explode(',', $names))));

            // `--modules=,` не называет ни одного модуля: молча ничего не ставить нельзя, CI решит, что модули добавлены.
            if ($modules === []) {
                $this->components->error(__('fastleo::console.install_modules_empty', ['modules' => implode(', ', array_keys(Modules::all()))]));

                return null;
            }

            return $modules;
        }

        $types = Modules::types();
        $type = $this->option('type');

        if (is_string($type) && $type !== '' && ! isset($types[$type])) {
            $this->components->error(__('fastleo::console.install_type_unknown', ['type' => $type, 'types' => implode(', ', array_keys($types))]));

            return null;
        }

        if (! is_string($type) || $type === '') {
            if (! $this->input->isInteractive()) {
                return [];
            }

            $type = (string) select(label: __('fastleo::console.install_type'), options: $types, default: 'basic');
        }

        // Базовая установка — без модулей; кастомная без вопросов (флаг --type=custom без --modules) тоже ничего не добавляет.
        if ($type === 'basic' || ! $this->input->isInteractive()) {
            return [];
        }

        if (! $this->pestInstalled($installer)) {
            return null;
        }

        $selected = [];

        while (true) {
            $selected = array_map(strval(...), array_values(multiselect(
                label: __('fastleo::console.install_modules'),
                options: array_map(fn (Module $module): string => $module->label.' — '.$module->description, Modules::all()),
                default: $selected,
            )));

            $conflicts = $installer->conflictsByModule(array_values(array_filter(array_map(Modules::find(...), $selected))));

            if ($conflicts === []) {
                return $selected;
            }

            $this->components->warn(__('fastleo::console.install_conflicts'));
            $this->components->bulletList(array_merge([], ...array_values($conflicts)));
            $selected = array_values(array_diff($selected, array_keys($conflicts)));

            // Всё выбранное занято: спрашивать ещё раз не о чем.
            if ($selected === []) {
                return [];
            }
        }
    }

    /**
     * Модули копируют на сайт свои тесты на Pest: без него установка с модулями отказывается до опроса и записи файлов.
     */
    private function pestInstalled(ModuleInstaller $installer): bool
    {
        if ($installer->hasPest()) {
            return true;
        }

        $this->components->error(__('fastleo::console.module_pest_required'));

        return false;
    }

    /**
     * Миграции, `storage:link`, примеры данных, пользователь и проверка — с подтверждением (без вопросов — по флагам).
     * `failed` — шаг, который просили выполнить, не удался (код выхода 1).
     *
     * Класс модели пользователя загружен до правки её файла и в этом процессе остался прежним, поэтому в запуске,
     * где модель только что подключена (`$child` задан), пользователь и проверка выполняются отдельным процессом.
     *
     * @param  list<string>  $modules
     * @return array{migrated: bool, admin: bool, failed: bool}
     */
    private function runSteps(array $modules, ?ChildArtisan $child): array
    {
        $interactive = $this->input->isInteractive();
        $setup = $this->option('setup') === true;
        $done = ['migrated' => false, 'admin' => false, 'failed' => false];

        $needsMigrate = $modules !== [] || Doctor::packageMigrationsPending();

        if ($needsMigrate && ($setup || ($interactive && confirm(__('fastleo::console.install_migrate'), default: ! app()->isProduction())))) {
            try {
                $done['migrated'] = $this->call('migrate', ['--force' => true]) === self::SUCCESS;
            } catch (Throwable $e) {
                $this->components->error(__('fastleo::console.install_migrate_failed', ['message' => $e->getMessage()]));
            }

            $done['failed'] = ! $done['migrated'];
        }

        if (Doctor::storageLinkMissing() && ($setup || ($interactive && confirm(__('fastleo::console.install_storage'), default: true)))) {
            $this->callSilently('storage:link');
        }

        // Таблицы готовы: миграции выполнены сейчас или нечего выполнять (модулей нет, миграции пакета были).
        if ($needsMigrate && ! $done['migrated']) {
            // Пользователя просили флагом, а таблиц ещё нет: молча пропустить нельзя (CI и Claude решат, что он создан).
            if ($this->adminRequested()) {
                $this->components->error(__('fastleo::console.install_admin_needs_migrate', ['email' => (string) $this->option('admin-email')]));
                $done['failed'] = true;
            }

            return $done;
        }

        $seeders = array_merge([], ...array_map(fn (string $name): array => Modules::find($name)->seeders ?? [], $modules));

        if ($seeders !== [] && app()->isProduction() && $this->option('seed') === true) {
            $this->components->warn(__('fastleo::console.install_seed_production'));
        }

        if ($seeders !== [] && ! app()->isProduction() && ($this->option('seed') === true || ($interactive && confirm(__('fastleo::console.install_seed'), default: false)))) {
            foreach ($seeders as $seeder) {
                try {
                    $this->callSilently('db:seed', ['--class' => $seeder]);
                } catch (Throwable $e) {
                    $this->components->error(__('fastleo::console.install_seed_failed', ['seeder' => $seeder, 'message' => $e->getMessage()]));
                    $done['failed'] = true;
                }
            }
        }

        $done['admin'] = $this->createAdmin($interactive, $child);
        $failedSeed = $done['failed'];
        $failedAdmin = $this->adminRequested() && ! $done['admin'];
        $failedDoctor = $this->runStep('fastleo:doctor', [], $interactive, $child) !== self::SUCCESS;
        $done['failed'] = $failedSeed || $failedAdmin || $failedDoctor;

        return $done;
    }

    /**
     * Выполнить команду в этом процессе или, если задан `$child`, отдельным процессом.
     *
     * @param  array<string, string|bool|null>  $arguments
     */
    private function runStep(string $command, array $arguments, bool $interactive, ?ChildArtisan $child): int
    {
        if (! $child instanceof ChildArtisan) {
            return $this->call($command, $arguments);
        }

        return $child->run($command, $arguments, $interactive, fn (string $buffer) => $this->output->write($buffer));
    }

    /**
     * Пользователя просили создать флагом.
     */
    private function adminRequested(): bool
    {
        $email = $this->option('admin-email');

        return is_string($email) && $email !== '';
    }

    /**
     * В таблице пользователей уже есть пользователь (`fastleo_admin`): спрашивать про нового незачем. Своя
     * `canAccessFastleo()` без этой колонки здесь не видна — тогда вопрос задаётся как обычно.
     */
    private function adminExists(): bool
    {
        $model = UserModel::className();

        if ($model === null || ! is_a($model, Model::class, true)) {
            return false;
        }

        $user = new $model();

        return Schema::hasColumn($user->getTable(), 'fastleo_admin') && $user->newQuery()->where('fastleo_admin', true)->exists();
    }

    /**
     * Создать пользователя: из флагов или по вопросу (email, имя и пароль спрашивает `fastleo:user`).
     */
    private function createAdmin(bool $interactive, ?ChildArtisan $child): bool
    {
        $email = $this->option('admin-email');

        if (is_string($email) && $email !== '') {
            $model = UserModel::className();

            if ($model !== null && is_a($model, Model::class, true) && $model::query()->where('email', $email)->exists()) {
                $this->components->warn(__('fastleo::console.install_admin_existing', ['email' => $email]));
            }

            return $this->runStep('fastleo:user', ['email' => $email, '--password' => $this->option('admin-password')], $interactive, $child) === self::SUCCESS;
        }

        if ($interactive && $child instanceof ChildArtisan && ! $child->canAsk()) {
            $this->components->warn(__('fastleo::console.install_admin_no_terminal'));

            return false;
        }

        // Пользователь уже есть: шаг выполнен, просить «создайте пользователя» в итоге незачем.
        if ($this->adminExists()) {
            return true;
        }

        return $interactive && confirm(__('fastleo::console.install_admin'), default: true) && $this->runStep('fastleo:user', [], $interactive, $child) === self::SUCCESS;
    }
}
