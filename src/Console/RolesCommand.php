<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Auth\RolePresets;
use Fastleo\Auth\UserLookup;
use Fastleo\Fastleo;
use Fastleo\Resources\Users\UserGuard;
use Fastleo\Support\UserModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Роль-владелец для ролей администраторов (`fastleo.roles.enabled`): создаёт её и по `--owner` выдаёт администратору.
 * Повторный запуск без `--owner` никого не повышает; с `--owner` — путь восстановления, если владельцев не осталось.
 * С `--presets` создаёт ещё и готовые роли по разделам сайта (существующие не трогает).
 *
 * ```bash
 * php artisan fastleo:roles --owner=admin@site.ru --presets
 * ```
 */
#[AsCommand(name: 'fastleo:roles')]
#[Description('Создать роль-владелец fastleo и выдать её администратору (--owner=email)')]
#[Signature('fastleo:roles {--owner= : Email администратора, которому выдать роль владельца} {--presets : Создать готовые роли «Администратор», «Редактор», «Наблюдатель»}')]
final class RolesCommand extends Command
{
    /**
     * Создать роль-владелец; выдать её администратору из `--owner`.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $roles = RoleMatrix::roleModel();

        if (! $roles instanceof Model || ! $this->tableExists($roles)) {
            $this->components->error(__('fastleo::console.roles_no_trait'));

            return self::FAILURE;
        }

        $owner = $roles->newQuery()->firstOrCreate(['name' => RoleMatrix::ownerRole(), 'guard_name' => Fastleo::GUARD]);

        if (! RoleMatrix::enabled()) {
            $this->components->warn(__('fastleo::console.roles_disabled'));
        }

        if ($this->option('presets') === true) {
            $created = RolePresets::create();
            $this->components->info($created === [] ? __('fastleo::console.roles_presets_exist') : __('fastleo::console.roles_presets_created', ['roles' => implode(', ', $created)]));
        }

        $email = $this->option('owner');

        if (is_string($email) && $email !== '') {
            return $this->grant($owner, Str::lower(trim($email)));
        }

        $this->components->info(__('fastleo::console.roles_ready', ['role' => RoleMatrix::ownerRole()]));

        $class = UserModel::fastleoClassName();

        if ($class !== null && ! UserGuard::owners(new $class)->exists()) {
            $this->components->warn(__('fastleo::console.roles_no_owner'));
        }

        return self::SUCCESS;
    }

    /**
     * Таблица ролей создана (миграции spatie выполнены).
     */
    private function tableExists(Model $roles): bool
    {
        return Schema::hasTable($roles->getTable());
    }

    /**
     * Выдать роль-владелец администратору.
     */
    private function grant(Model $owner, string $email): int
    {
        $user = UserLookup::adminByEmail($email);
        $relation = $user instanceof Model && method_exists($user, 'roles') ? $user->roles() : null;

        if (! $relation instanceof MorphToMany) {
            $this->components->error(__('fastleo::console.roles_admin_not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $relation->syncWithoutDetaching([$owner->getKey()]);

        if (method_exists($user, 'forgetCachedPermissions')) {
            $user->forgetCachedPermissions();
        }

        $this->components->info(__('fastleo::console.roles_granted', ['email' => $email, 'role' => RoleMatrix::ownerRole()]));

        return self::SUCCESS;
    }
}
