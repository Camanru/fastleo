<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Page;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Свои страницы админки (`Fastleo\Page`): шаблон и данные задаёт класс страницы.
 */
final class PagesController
{
    /**
     * Показать страницу; нет такой или она сейчас недоступна — 404, нет доступа — 403.
     */
    public function show(Request $request, string $page): View
    {
        $found = Fastleo::resources()->findPage($page);
        abort_unless($found instanceof Page && $found->isAvailable(), 404);
        abort_unless($found->canAccess(Fastleo::guard()->user()), 403);

        return resolve(ViewFactory::class)->first([$found->view()], $found->data($request));
    }
}
