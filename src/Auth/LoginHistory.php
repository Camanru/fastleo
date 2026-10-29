<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * История входов администратора: запись входа, список устройств, письмо о новом устройстве.
 *
 * Устройство — пара «IP + строка браузера»; повторный вход с него обновляет время, а не добавляет строку.
 */
final readonly class LoginHistory
{
    /**
     * Слушатель события входа: пишет вход и, если устройство новое для администратора,
     * который уже входил раньше, отправляет письмо (если оно не выключено в `fastleo.auth.new_login_mail`).
     *
     * Сбой почты не мешает входу.
     */
    public function handle(Login $event): void
    {
        if ($event->guard !== Fastleo::GUARD) {
            return;
        }

        $request = request();
        $known = $this->hasHistory($event->user);
        $isNew = $this->record($event->user, $request);

        if ($isNew && $known && config()->boolean('fastleo.auth.new_login_mail', true)) {
            $this->notifyNewLogin($event->user, $request);
        }
    }

    /**
     * Записать вход. Возвращает true, если с этого устройства администратор вошёл впервые.
     */
    public function record(Authenticatable $user, Request $request): bool
    {
        $ip = Str::limit((string) $request->ip(), 45, '');
        $agent = Str::limit($request->userAgent() ?? '', 255, '');
        $key = $this->userKey($user);
        $fingerprint = sha1($ip.'|'.$agent);

        $record = LoginRecord::query()->where('user_id', $key)->where('fingerprint', $fingerprint)->first();

        if ($record instanceof LoginRecord) {
            $record->update(['last_seen_at' => now()]);

            return false;
        }

        LoginRecord::query()->create([
            'user_id' => $key,
            'fingerprint' => $fingerprint,
            'ip' => $ip,
            'user_agent' => $agent === '' ? null : $agent,
            'created_at' => now(),
            'last_seen_at' => now(),
        ]);

        return true;
    }

    /**
     * Устройства администратора, свежие сверху.
     *
     * @return Collection<int, LoginRecord>
     */
    public function devices(Authenticatable $user): Collection
    {
        return LoginRecord::query()->where('user_id', $this->userKey($user))->latest('last_seen_at')->limit(50)->get();
    }

    /**
     * Отпечаток устройства текущего запроса — чтобы пометить «это устройство» в списке.
     */
    public function currentFingerprint(Request $request): string
    {
        return sha1(Str::limit((string) $request->ip(), 45, '').'|'.Str::limit($request->userAgent() ?? '', 255, ''));
    }

    /**
     * Забыть все устройства администратора, кроме текущего (после «выйти на других устройствах»).
     */
    public function forgetOthers(Authenticatable $user, Request $request): void
    {
        LoginRecord::query()
            ->where('user_id', $this->userKey($user))
            ->where('fingerprint', '!=', $this->currentFingerprint($request))
            ->delete();
    }

    /**
     * Удалить записи, в которых давно не было входов.
     */
    public function prune(): int
    {
        $days = max(1, config()->integer('fastleo.auth.logins_keep_days', 90));

        return LoginRecord::query()->toBase()->where('last_seen_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Входил ли администратор раньше.
     */
    private function hasHistory(Authenticatable $user): bool
    {
        return LoginRecord::query()->where('user_id', $this->userKey($user))->exists();
    }

    /**
     * Письмо «вход с нового устройства». Адрес берётся из модели, поэтому отдельный Notifiable не нужен.
     */
    private function notifyNewLogin(Authenticatable $user, Request $request): void
    {
        $email = UserLookup::emailOf($user);

        if ($email === '') {
            return;
        }

        rescue(fn () => Notification::route('mail', $email)->notify(
            new NewLoginNotification((string) $request->ip(), $request->userAgent() ?? '', now()),
        ));
    }

    /**
     * Ключ пользователя в таблице входов.
     */
    private function userKey(Authenticatable $user): string
    {
        $key = $user->getAuthIdentifier();

        return is_scalar($key) ? (string) $key : '';
    }
}
