<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Registry\UsersSubclassSecond;

use Fastleo\Resources\UserResource;

/**
 * Наследник встроенного раздела «Администраторы» идёт вторым: поиск наследника ведётся по классу, который проверяется сейчас.
 */
final class SubAdminResource extends UserResource {}
