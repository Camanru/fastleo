<?php

declare(strict_types=1);

namespace Fastleo\SoftNetica;

use Fastleo\Fastleo;
use Fastleo\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Override;

/**
 * Встроенный раздел «SoftNetica»: статус сайта, бэкапы по ночам, сертификаты и домен, безопасность, счета и отчёты.
 * Есть только у подключённого сайта (токен введён и не отклонён), иначе адрес отвечает 404. Данные берутся из последней
 * синхронизации; печати счетов и актов здесь нет — только ссылки в кабинет SoftNetica.
 */
final class SoftNeticaPage extends Page
{
    protected static ?string $label = 'SoftNetica';

    protected static string $icon = 'cloud';

    protected static int $position = 800;

    protected static ?string $slug = 'softnetica';

    #[Override]
    public function view(): string
    {
        return 'fastleo::softnetica.show';
    }

    /**
     * Группа меню «Система».
     */
    #[Override]
    public static function group(): ?string
    {
        $group = __('fastleo::users.group', [], Fastleo::LOCALE);

        return is_string($group) ? $group : null;
    }

    /**
     * Данные для шаблона: разобранный ответ SoftNetica и признак «данные устарели».
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function data(Request $request): array
    {
        $state = resolve(SyncState::class);
        $client = $state->client();

        return [
            'data' => $client === null ? null : ClientData::from($client),
            'stale' => $state->isStale(),
            'syncedAt' => $state->syncedAt(),
        ];
    }

    /**
     * Раздел существует, пока сайт подключён.
     */
    #[Override]
    public function isAvailable(): bool
    {
        return Connection::connected();
    }

    /**
     * Доступ — всем администраторам, пока функция включена в конфиге.
     */
    #[Override]
    public function canAccess(?Authenticatable $user): bool
    {
        return Connection::enabled();
    }
}
