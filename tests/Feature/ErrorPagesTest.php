<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    useRoles();
    $this->observer = roleAdmin(role('reader', ['articles.view']));
});

it('shows a styled page for a forbidden section inside the menu of the admin', function (): void {
    $this->actingAs($this->observer, Fastleo::GUARD);

    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertForbidden()
        ->assertSee('Нет доступа')
        ->assertSee('обратитесь к владельцу сайта')
        ->assertSee('На главную')
        ->assertSee('Статьи');

    // Заголовок выводит макет (два тега: телефон и компьютер), карточка ошибки его не повторяет.
    expect(substr_count($this->get(route('fastleo.resources.create', 'articles'))->getContent(), '<h1'))->toBe(2);
});

it('shows a styled page for an address of the admin that no route knows', function (): void {
    $this->get('/fastleo/a/b/c/d/e/f/g')
        ->assertNotFound()
        ->assertSee('Страница не найдена')
        ->assertSee('Войти')
        ->assertDontSee('админк')
        // Кнопка «Назад» получает адрес готовой строкой JS, а не необработанной директивой Blade.
        ->assertSee('(location.href = '.Js::from(route('fastleo.dashboard'))->toHtml().')', false)
        ->assertDontSee('@js(', false);
});

it('shows a styled page for a section that does not exist', function (): void {
    $this->actingAs($this->observer, Fastleo::GUARD);

    $this->get('/fastleo/no-such-section')->assertNotFound()->assertSee('Страница не найдена')->assertSee('На главную');
});

it('shows a styled page for a record that does not exist', function (): void {
    $this->actingAs($this->observer, Fastleo::GUARD);

    $this->get(route('fastleo.resources.show', ['articles', 999999]))->assertNotFound()->assertSee('Страница не найдена');
});

it('asks to reload the page when the form has no valid token', function (): void {
    $this->app['env'] = 'production';

    $this->post(route('fastleo.login.store'), ['email' => 'a@b.c', 'password' => 'x'])
        ->assertStatus(419)
        ->assertSee('Страница устарела')
        ->assertSee('Обновить страницу');
});

it('says when to try again after too many requests', function (): void {
    $this->startSession();
    Sleep::fake();

    foreach (range(1, 6) as $attempt) {
        $response = $this->post(route('fastleo.password.email'), ['email' => 'nobody@example.com', '_token' => csrf_token()]);
    }

    $response->assertStatus(429)->assertSee('Слишком много запросов')->assertSee('Повторите через');
});

it('leaves the window without the menu when the page is opened in a frame', function (): void {
    $this->actingAs($this->observer, Fastleo::GUARD);

    $this->get(route('fastleo.resources.create', ['articles', 'frame' => 1]))
        ->assertForbidden()
        ->assertSee('Нет доступа')
        ->assertDontSee('data-search', false);
});

it('answers a request that waits for JSON with JSON, not with a page', function (): void {
    $this->actingAs($this->observer, Fastleo::GUARD);

    $this->getJson(route('fastleo.resources.create', 'articles'))->assertForbidden()->assertJsonMissingPath('foo')->assertDontSee('Нет доступа');
});

it('does not touch the error pages of the site itself', function (): void {
    $this->get('/no-such-site-page')->assertNotFound()->assertDontSee('Страница не найдена');
});

it('does not touch the errors that have no page of their own', function (): void {
    $this->get(route('fastleo.logout'))->assertStatus(405)->assertDontSee('Нет доступа');
});

it('does not take the same path on another domain for the admin', function (): void {
    config(['fastleo.domain' => 'admin.example.test']);

    $this->get('/fastleo/a/b/c/d/e/f/g')->assertNotFound()->assertDontSee('Страница не найдена');
});

/**
 * Админка на корне домена сайта: маршруты пакета загружаются первыми, маршруты сайта — после них.
 */
function adminAtSiteRoot(?string $domain = null): void
{
    config(['fastleo.path' => '', 'fastleo.domain' => $domain]);
    Route::setRoutes(new RouteCollection());
    require dirname(__DIR__, 2).'/routes/fastleo.php';
    Route::get('/private.html', fn () => abort(403));
    Route::get('/up', fn (): string => 'healthy');
    resolve('url')->setRoutes(Route::getRoutes());
}

it('shows the admin page for an address without a route when the admin sits at the site root', function (): void {
    adminAtSiteRoot();

    $this->get('/a/b/c/d/e/f/g')->assertNotFound()->assertSee('Страница не найдена');
});

it('leaves the errors of site routes to the site when the admin sits at the site root', function (): void {
    adminAtSiteRoot();

    $this->get('/private.html')->assertForbidden()->assertDontSee('Нет доступа');
});

it('treats any address of its own domain as the admin when the path is empty', function (): void {
    adminAtSiteRoot('admin.example.test');

    $this->get('http://admin.example.test/private.html')->assertForbidden()->assertSee('Нет доступа');
});

it('leaves the health check route of the site free when the admin sits at the site root', function (): void {
    adminAtSiteRoot();

    $this->get('/up')->assertOk()->assertSee('healthy');
});
