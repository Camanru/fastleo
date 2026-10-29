<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Auth\LoginThrottle;
use Fastleo\Auth\UserLookup;
use Fastleo\Fastleo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Снимает блокировку входа администратора после слишком большого числа неверных паролей или кодов 2FA за час.
 *
 * ```bash
 * php artisan fastleo:unlock admin@site.ru
 * ```
 */
#[AsCommand(name: 'fastleo:unlock')]
#[Description('Снять блокировку входа администратора fastleo (слишком много неверных паролей)')]
#[Signature('fastleo:unlock {email : Email администратора}')]
final class UnlockCommand extends Command
{
    /**
     * Сбросить часовые счётчики неудачных входов пользователя (пароль и код 2FA).
     */
    public function handle(LoginThrottle $throttle): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $email = Str::lower(trim($this->argument('email')));
        $user = UserLookup::byEmail($email);

        if (! $user instanceof Authenticatable) {
            $this->components->error(__('fastleo::console.unlock_not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $identifier = $user->getAuthIdentifier();
        $throttle->unlock(is_scalar($identifier) ? (string) $identifier : '');
        $this->components->info(__('fastleo::console.unlock_done', ['email' => $email]));

        return self::SUCCESS;
    }
}
