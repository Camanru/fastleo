<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Resource;

/**
 * Стартовая страница админки: куда ведут вход в админку и логотип.
 *
 * Задаётся ключом `home` в `config/fastleo.php` — адрес раздела или своей страницы (`'leads'`).
 * Если ключ пуст, раздела нет или администратору он закрыт Policy, стартовой остаётся страница «Дашборд».
 */
final class Home
{
    /**
     * Адрес стартовой страницы.
     *
     * ```php
     * return redirect()->to(Home::url());
     * ```
     */
    public static function url(): string
    {
        $slug = config('fastleo.home');

        if (! is_string($slug) || $slug === '') {
            return route('fastleo.dashboard');
        }

        $registry = Fastleo::resources();
        $resource = $registry->find($slug);

        if ($resource instanceof Resource) {
            return Authorization::allows('viewAny', $resource) ? route('fastleo.resources.index', $slug) : route('fastleo.dashboard');
        }

        $page = $registry->findPage($slug);

        if ($page instanceof Page && $page->isAvailable() && $page->canAccess(Fastleo::guard()->user())) {
            return route('fastleo.pages.show', $slug);
        }

        return route('fastleo.dashboard');
    }
}
