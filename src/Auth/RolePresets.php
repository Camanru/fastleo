<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Fastleo\Fastleo;
use Fastleo\Notifications\NotificationBotResource;
use Fastleo\Publishing\ChannelResource;
use Fastleo\Publishing\PublicationResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Готовые роли: «Администратор» (всё, кроме управления владельцем), «Редактор» (без удаления), «Наблюдатель» (только просмотр).
 *
 * Создаются командой `fastleo:roles --presets` из разделов, которые есть на сайте сейчас; роль, которая уже есть, не
 * трогается — владелец мог поменять её права. Новые разделы получают права в разделе «Роли».
 *
 * ```php
 * RolePresets::create(); // ['Администратор', 'Редактор', 'Наблюдатель'] — созданные роли
 * ```
 */
final class RolePresets
{
    /**
     * Ключ перевода названия → группы прав и доступ к разделам доставки сообщений («Каналы», «Боты уведомлений», «Публикации»):
     * там можно подставить свой бот или чат, и заявки с данными клиентов пойдут к нему, поэтому их получает только администратор.
     *
     * @var array<string, array{groups: list<string>, messengers: bool}>
     */
    public const array PRESETS = [
        'preset_admin' => ['groups' => RolePermissions::GROUPS, 'messengers' => true],
        'preset_editor' => ['groups' => ['view', 'create', 'update'], 'messengers' => false],
        'preset_viewer' => ['groups' => ['view'], 'messengers' => false],
    ];

    /** Разделы доставки сообщений: адрес определяется по классу, а не по имени (сайт мог сменить `$slug`). */
    public const array MESSENGER_RESOURCES = [NotificationBotResource::class, ChannelResource::class, PublicationResource::class];

    /**
     * Создать недостающие готовые роли и выдать им права по разделам.
     *
     * @return list<string> имена созданных ролей
     */
    public static function create(): array
    {
        $roles = RoleMatrix::roleModel();

        if (! $roles instanceof Model) {
            return [];
        }

        $created = [];

        foreach (self::PRESETS as $key => $preset) {
            $name = __('fastleo::roles.'.$key, [], Fastleo::LOCALE);
            $role = $roles->newQuery()->firstOrCreate(['name' => $name, 'guard_name' => Fastleo::GUARD]);

            if (! $role->wasRecentlyCreated) {
                continue;
            }

            $closed = $preset['messengers'] ? [] : self::messengerSlugs();

            RolePermissions::sync($role, array_values(array_filter(RolePermissions::names(), function (string $permission) use ($preset, $closed): bool {
                [, $slug, $group] = explode('.', $permission);

                return in_array($group, $preset['groups'], true) && ! in_array($slug, $closed, true);
            })));
            $created[] = $name;
        }

        return $created;
    }

    /**
     * Адреса разделов доставки сообщений на этом сайте.
     *
     * @return list<string>
     */
    private static function messengerSlugs(): array
    {
        $slugs = [];

        foreach (Fastleo::resources()->all() as $slug => $class) {
            if (array_any(self::MESSENGER_RESOURCES, fn (string $messenger): bool => is_a($class, $messenger, true))) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
