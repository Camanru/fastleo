<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Imports\Import;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Publishing\Publication;
use Fastleo\Support\UserModel;
use Fastleo\Widgets\DashboardLayout;
use Illuminate\Database\Eloquent\Model;

/**
 * Данные пакета об пользователе уходят вместе с ним: секрет 2FA, история входов, раскладка дашборда, колокольчик
 * (в уведомлениях о входах — адрес и браузер) и отчёты его импортов вместе с файлами. Публикации остаются (это
 * материалы сайта), но без автора: по нему доставка при повторе ищет пользователя.
 *
 * Их ключ — значение, а не внешний ключ базы: оставшиеся строки достались бы новому пользователю, которому база выдала
 * тот же id (MySQL до 8 и SQLite после удаления последней строки), — с чужим секретом 2FA или устройствами.
 *
 * ```php
 * UserCleanup::forget($user); // вызывает провайдер при удалении модели пользователя
 * ```
 */
final class UserCleanup
{
    /**
     * Удалить данные пакета об удалённом пользователе. Мягкое удаление (его можно отменить) данные не трогает.
     */
    public static function forget(Model $user): void
    {
        $class = UserModel::fastleoClassName();

        if ($class === null || ! $user instanceof $class || (method_exists($user, 'isForceDeleting') && ! $user->isForceDeleting())) {
            return;
        }

        $id = $user->getKey();
        $key = is_scalar($id) ? (string) $id : '';

        TwoFactorCredential::query()->where('user_id', $key)->delete();
        LoginRecord::query()->where('user_id', $key)->delete();
        DashboardLayout::query()->where('user_id', $key)->delete();
        AdminNotification::query()->toBase()->where('user_id', $key)->delete();
        Publication::query()->toBase()->where('user_id', $key)->update(['user_id' => null]);

        $disk = Import::disk();

        // По ключу, а не по смещению: удаление внутри порции со смещением пропускало бы часть записей.
        Import::query()->where('user_id', $key)->eachById(function (Import $import) use ($disk): void {
            $disk->delete($import->files());
            $import->delete();
        });
    }
}
