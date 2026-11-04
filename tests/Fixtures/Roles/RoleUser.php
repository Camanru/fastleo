<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Roles;

use Illuminate\Database\Eloquent\Attributes\Table;
use Spatie\Permission\Traits\HasRoles;
use Workbench\App\Models\User;

/**
 * Пользователь демо-сайта с ролями spatie: та же таблица `users` и те же Policy, плюс трейт `HasRoles`.
 * Нужен только тестам ролей: на рабочую модель трейт не вешаем, иначе его хук удаления полез бы в таблицы ролей
 * во всех остальных тестах.
 */
#[Table(name: 'users')]
final class RoleUser extends User
{
    use HasRoles;
}
