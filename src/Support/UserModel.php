<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Auth\FastleoUser;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель пользователей, поверх которой работает guard fastleo.
 */
final class UserModel
{
    /**
     * Класс модели из провайдера guard fastleo в config/auth.php — того же, через который идёт вход.
     *
     * Guard регистрирует пакет по `fastleo.auth.provider`, но сайт может описать guard fastleo
     * сам — тогда берётся провайдер из его описания.
     *
     * ```php
     * UserModel::className(); // 'App\Models\User'
     * ```
     */
    public static function className(): ?string
    {
        $provider = config('auth.guards.'.Fastleo::GUARD.'.provider', config('fastleo.auth.provider', 'users'));
        $model = config('auth.providers.'.(is_string($provider) ? $provider : 'users').'.model');

        return is_string($model) && class_exists($model) ? $model : null;
    }

    /**
     * Модель подходит для админки: Eloquent и реализует FastleoUser.
     *
     * @return class-string<Model&FastleoUser>|null
     */
    public static function fastleoClassName(): ?string
    {
        $model = self::className();

        if ($model !== null && is_a($model, Model::class, true) && is_a($model, FastleoUser::class, true)) {
            return $model;
        }

        return null;
    }
}
