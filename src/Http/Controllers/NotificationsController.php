<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Колокольчик и раздел «Уведомления»: уведомления вошедшего администратора, «прочитано», «прочитать все».
 *
 * Администратор видит и меняет только свои уведомления; чужой номер — 404. Колокольчик выключен
 * (`fastleo.notifications.bell`) — все адреса отвечают 404.
 */
final class NotificationsController
{
    /** Сколько уведомлений в выпадающем списке. */
    private const int LIMIT = 20;

    /** Сколько уведомлений на странице раздела «Уведомления». */
    private const int PER_PAGE = 30;

    /**
     * Раздел «Уведомления»: все свои уведомления за срок хранения, новые сверху; `?unread=1` — только непрочитанные.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $key = $this->key();
        $onlyUnread = $request->query('unread') === '1';

        $notifications = AdminNotification::query()
            ->where('user_id', $key)
            ->when($onlyUnread, fn (Builder $query): Builder => $query->whereNull('read_at'))
            ->latest('created_at')
            ->latest('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AdminNotification $notification): array => $this->present($notification, 'd.m.Y H:i'));

        // Страница опустела (всё прочитали в фильтре «Непрочитанные», адрес за последней страницей) — на первую.
        if ($notifications->isEmpty() && $notifications->currentPage() > 1) {
            return to_route('fastleo.notifications.index', $onlyUnread ? ['unread' => 1] : []);
        }

        return view('fastleo::notifications.index', [
            'notifications' => $notifications,
            'onlyUnread' => $onlyUnread,
            'unread' => AdminNotification::query()->where('user_id', $key)->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Уведомления и число непрочитанных (JSON для колокольчика); с `?count=1` — только число (для обновления значка).
     */
    public function feed(Request $request): JsonResponse
    {
        $key = $this->key();
        $unread = AdminNotification::query()->where('user_id', $key)->whereNull('read_at')->count();

        if ($request->query('count') === '1') {
            return response()->json(['unread' => $unread]);
        }

        $items = AdminNotification::query()->where('user_id', $key)->latest('created_at')->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (AdminNotification $notification): array => $this->present($notification, 'd.m H:i'))->all();

        return response()->json(['unread' => $unread, 'items' => $items]);
    }

    /**
     * Отметить своё уведомление прочитанным. Из колокольчика (JSON) — ответ `ok`; с формы раздела «Уведомления» —
     * переход по ссылке уведомления, а без ссылки — назад к списку.
     */
    public function read(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $notification = AdminNotification::query()->where('user_id', $this->key())->whereKey($id)->first();
        abort_unless($notification instanceof AdminNotification, 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        $url = $request->boolean('open') ? $this->safeUrl($notification->data['url'] ?? null) : null;

        return $url === null ? $this->backToList($request) : redirect()->away($url);
    }

    /**
     * Отметить все свои уведомления прочитанными: из колокольчика — JSON, с раздела «Уведомления» — назад к списку.
     */
    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        AdminNotification::query()->where('user_id', $this->key())->whereNull('read_at')->toBase()->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : $this->backToList($request, keepPage: false)->with('fastleo.success', __('fastleo::notifications.all_read', [], Fastleo::LOCALE));
    }

    /**
     * Назад в раздел «Уведомления» с тем же фильтром и страницей из формы (`unread`, `page`). Адрес строится явно,
     * а не по Referer: без него `back()` увёл бы на последний GET сессии — JSON опроса колокольчика. После «Прочитать все»
     * (`$keepPage = false`) — на первую страницу: прежняя опустела бы, и второй редирект потерял бы сообщение.
     * Номер страницы — не длиннее 6 цифр: огромный номер ломает расчёт смещения выборки.
     */
    private function backToList(Request $request, bool $keepPage = true): RedirectResponse
    {
        $page = $request->input('page');

        return to_route('fastleo.notifications.index', array_filter([
            'unread' => $request->input('unread') === '1' ? 1 : null,
            'page' => $keepPage && is_string($page) && preg_match('/^\d{1,6}$/', $page) === 1 && (int) $page > 1 ? (int) $page : null,
        ]));
    }

    /**
     * Уведомление для списка: заголовок, текст, безопасная ссылка, иконка, уровень, прочитано ли, время в формате `$format`.
     *
     * @return array{id: string, title: string, body: string, url: ?string, icon: string, level: string, read: bool, time: ?string}
     */
    private function present(AdminNotification $notification, string $format): array
    {
        // Не строки (запись в обход FastleoMessage, испорченная база) — пусто, а не ошибка всей страницы.
        $text = fn (string $key, string $default): string => is_string($notification->data[$key] ?? null) ? $notification->data[$key] : $default;

        return [
            'id' => $notification->id,
            'title' => $text('title', ''),
            'body' => $text('body', ''),
            'url' => $this->safeUrl($notification->data['url'] ?? null),
            'icon' => $text('icon', 'bell'),
            'level' => $text('level', 'info'),
            'read' => $notification->read_at !== null,
            'time' => $notification->created_at?->timezone(Fastleo::timezone())->format($format),
        ];
    }

    /**
     * Ключ вошедшего администратора; колокольчик выключен — 404.
     */
    private function key(): string
    {
        $user = Fastleo::guard()->user();
        abort_unless(Fastleo::bellFor($user), 404);
        $key = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;
        abort_unless(is_scalar($key), 403);

        return (string) $key;
    }

    /**
     * Ссылка только http(s) или адрес сайта: чужие схемы (`javascript:`) в колокольчик не попадают.
     */
    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('~^(https?://|/(?![/\\\\\x00-\x20]))~i', $url) === 1 ? $url : null;
    }
}
