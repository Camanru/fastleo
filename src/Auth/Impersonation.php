<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Fastleo\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * «Войти как»: владелец временно входит в панель под другим пользователем, чтобы проверить его права, и возвращается.
 *
 * Включается ключом `fastleo.users.impersonate` и только когда сайт знает, кто владелец (роли или `Fastleo::resolveOwnerUsing()`):
 * иначе владельцем нельзя было бы называть никого и функция открылась бы всем. Войти можно под пользователем с доступом в
 * панель, который сам не владелец. Состояние — в сессии; на каждом запросе его перепроверяет `ValidateImpersonation`.
 *
 * ```php
 * Fastleo::impersonator(); // владелец, который сейчас вошёл под чужим именем, или null
 * ```
 */
final class Impersonation
{
    /** Ключ сессии с состоянием подмены. */
    public const string SESSION_KEY = 'fastleo.impersonator';

    /** Сколько секунд длится подмена: потом выход, чтобы забытая сессия не жила без срока. */
    public const int LIFETIME = 3600;

    /** Идёт вход, который не нужно записывать в историю входов и по которому не пишут письмо «вход с нового устройства». */
    private static bool $silent = false;

    /**
     * Функция включена и сайт знает владельца.
     */
    public static function enabled(): bool
    {
        return config()->boolean('fastleo.users.impersonate', false) && (RoleMatrix::enabled() || Fastleo::hasOwnerResolver());
    }

    /**
     * Войти под пользователем может владелец: функция включена, есть сессия, у пользователя есть доступ в панель и он не владелец
     * (это же исключает вход под собой).
     */
    public static function mayStart(?Authenticatable $actor, Authenticatable $target): bool
    {
        return self::enabled()
            && request()->hasSession()
            && $actor instanceof Authenticatable
            && Fastleo::isOwner($actor)
            && $target instanceof FastleoUser
            && $target->canAccessFastleo()
            && ! Fastleo::isOwner($target);
    }

    /**
     * Войти под пользователем. Владелец не выходит (иначе у него поменялся бы токен «запомнить меня»), событие входа в историю
     * входов не попадает.
     *
     * @param  string  $returnUrl  куда вернуть владельца после «Вернуться»
     */
    public static function start(Authenticatable $owner, Authenticatable $target, string $returnUrl): void
    {
        $guard = Fastleo::guard();
        $hash = $guard->hashPasswordForCookie($owner->getAuthPassword());

        self::$silent = true;

        try {
            $guard->login($target);
        } finally {
            self::$silent = false;
        }

        session()->put(self::SESSION_KEY, [
            'owner' => self::identifierOf($owner),
            'target' => self::identifierOf($target),
            'hash' => $hash,
            'at' => now()->getTimestamp(),
            'return' => $returnUrl,
        ]);

        Log::warning('Fastleo: owner signed in as another user.', ['owner' => self::identifierOf($owner), 'target' => self::identifierOf($target)]);
    }

    /**
     * Вернуться к своей учётной записи. Ложь, если подмены нет или владельца больше нельзя пускать (тогда это обычный выход).
     */
    public static function leave(): bool
    {
        $state = self::state();
        $owner = self::validOwner($state);

        if ($state === null || ! $owner instanceof Authenticatable) {
            return false;
        }

        self::$silent = true;

        try {
            Fastleo::guard()->login($owner);
        } finally {
            self::$silent = false;
        }

        session()->forget([self::SESSION_KEY, TwoFactorChallengeController::SESSION_KEY, 'url.intended']);

        Log::warning('Fastleo: owner returned from another user.', ['owner' => $state['owner'], 'target' => $state['target']]);

        return true;
    }

    /**
     * Идёт подмена (в сессии есть её состояние).
     */
    public static function active(): bool
    {
        return self::state() !== null;
    }

    /**
     * Владелец, который сейчас вошёл под чужим именем, или null: подмены нет, нет сессии (консоль, очередь) или подмена уже недействительна (срок, владелец, вошедший не тот).
     */
    public static function impersonator(): ?Authenticatable
    {
        return self::valid() ? self::validOwner(self::state()) : null;
    }

    /**
     * Куда вести после «Вернуться».
     */
    public static function returnUrl(): ?string
    {
        $url = self::state()['return'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Подмена в порядке: функция всё ещё включена, владелец на месте и всё ещё владелец, срок не вышел, вошёл тот, под кем начали, и он не стал владельцем.
     */
    public static function valid(): bool
    {
        $state = self::state();
        $current = Fastleo::guard()->user();

        return $state !== null
            && self::enabled()
            && now()->getTimestamp() - $state['at'] <= self::LIFETIME
            && self::validOwner($state) instanceof Authenticatable
            && $current instanceof Authenticatable
            && self::identifierOf($current) === $state['target']
            && ! Fastleo::isOwner($current);
    }

    /**
     * Выйти из панели с этого устройства. При подмене — без смены токена «запомнить меня» у того, под кем вошли: его настоящие
     * устройства остаются в системе.
     */
    public static function signOut(): void
    {
        if (self::active()) {
            Fastleo::guard()->logoutCurrentDevice();
            session()->forget(self::SESSION_KEY);

            return;
        }

        Fastleo::guard()->logout();
    }

    /**
     * Убрать состояние подмены (обычный вход, второй шаг входа).
     */
    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Идёт вход, который не нужно записывать в историю входов.
     */
    public static function silent(): bool
    {
        return self::$silent;
    }

    /**
     * Состояние из сессии или null, если его нет или оно неверного вида.
     *
     * @return array{owner: string, target: string, hash: string, at: int, return: string}|null
     */
    private static function state(): ?array
    {
        if (! request()->hasSession()) {
            return null;
        }

        $state = session()->get(self::SESSION_KEY);

        if (! is_array($state) || ! is_string($state['owner'] ?? null) || ! is_string($state['target'] ?? null) || ! is_string($state['hash'] ?? null) || ! is_int($state['at'] ?? null)) {
            return null;
        }

        return ['owner' => $state['owner'], 'target' => $state['target'], 'hash' => $state['hash'], 'at' => $state['at'], 'return' => is_string($state['return'] ?? null) ? $state['return'] : ''];
    }

    /**
     * Владелец из состояния, если он по-прежнему владелец с доступом и его пароль не менялся с начала подмены.
     *
     * @param  array{owner: string, target: string, hash: string, at: int, return: string}|null  $state
     */
    private static function validOwner(?array $state): ?Authenticatable
    {
        if ($state === null) {
            return null;
        }

        $guard = Fastleo::guard();
        $owner = $guard->getProvider()->retrieveById($state['owner']);

        if ($owner instanceof FastleoUser
            && $owner->canAccessFastleo()
            && Fastleo::isOwner($owner)
            && hash_equals($state['hash'], $guard->hashPasswordForCookie($owner->getAuthPassword()))) {
            return $owner;
        }

        return null;
    }

    /**
     * Идентификатор строкой.
     */
    public static function identifierOf(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : '';
    }
}
