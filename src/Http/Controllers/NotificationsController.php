<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Колокольчик: список уведомлений вошедшего администратора, «прочитано», «прочитать все».
 *
 * Администратор видит и меняет только свои уведомления; чужой номер — 404.
 */
final class NotificationsController
{
    /** Сколько уведомлений в выпадающем списке. */
    private const int LIMIT = 20;

    /**
     * Уведомления и число непрочитанных (JSON); с `?count=1` — только число (для обновления значка).
     */
    public function index(Request $request): JsonResponse
    {
        $key = $this->key();
        $unread = AdminNotification::query()->where('user_id', $key)->whereNull('read_at')->count();

        if ($request->query('count') === '1') {
            return response()->json(['unread' => $unread]);
        }

        $items = AdminNotification::query()->where('user_id', $key)->latest('created_at')->limit(self::LIMIT)->get()->map(fn (AdminNotification $notification): array => [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? '',
            'body' => $notification->data['body'] ?? '',
            'url' => $this->safeUrl($notification->data['url'] ?? null),
            'icon' => $notification->data['icon'] ?? 'bell',
            'level' => $notification->data['level'] ?? 'info',
            'read' => $notification->read_at !== null,
            'time' => $notification->created_at?->timezone(Fastleo::timezone())->format('d.m H:i'),
        ])->all();

        return response()->json(['unread' => $unread, 'items' => $items]);
    }

    /**
     * Отметить своё уведомление прочитанным.
     */
    public function read(string $id): JsonResponse
    {
        $notification = AdminNotification::query()->where('user_id', $this->key())->whereKey($id)->first();
        abort_unless($notification instanceof AdminNotification, 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Отметить все свои уведомления прочитанными.
     */
    public function readAll(): JsonResponse
    {
        AdminNotification::query()->where('user_id', $this->key())->whereNull('read_at')->toBase()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Ключ вошедшего администратора.
     */
    private function key(): string
    {
        $user = Fastleo::guard()->user();
        $key = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
        abort_unless(is_scalar($key), 403);

        return (string) $key;
    }

    /**
     * Ссылка только http(s) или адрес сайта: чужие схемы (`javascript:`) в колокольчик не попадают.
     */
    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('~^(https?://|/(?![/\\\\]))~i', $url) === 1 ? $url : null;
    }
}
