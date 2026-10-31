<?php

declare(strict_types=1);

namespace Fastleo\Http\Middleware;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Page;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Права своей страницы на её маршрутах из `Fastleo::routes(..., page: ReportPage::class)`: те же проверки,
 * что при открытии страницы, — недоступная страница (не найдена, `isAvailable()` = false) отвечает 404,
 * закрытая для администратора (`canAccess()`) — 403.
 */
final class AuthorizePage
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $page  класс страницы
     */
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $slug = array_search($page, Fastleo::resources()->pages(), true);
        $found = is_string($slug) ? Fastleo::resources()->findPage($slug) : null;

        abort_unless($found instanceof Page && $found->isAvailable(), 404);
        abort_unless($found->canAccess(Fastleo::guard()->user()), 403);

        return $next($request);
    }
}
