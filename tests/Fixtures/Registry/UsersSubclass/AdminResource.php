<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\UsersSubclass;

use Fastleo\Resources\UserResource;

/**
 * Наследник встроенного раздела «Администраторы», положенный в папку разделов без настройки `fastleo.users.resource`.
 */
final class AdminResource extends UserResource {}
