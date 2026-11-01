<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Tests\Fixtures\Pages\SecretPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

beforeEach(function (): void {
    useFixtureResources('Pages');
    View::addNamespace('pages-fixture', __DIR__.'/../../Fixtures/Pages/views');
    $this->actingAs(admin(['email' => 'anna@example.com']), Fastleo::GUARD);
});

it('shows the page with its data inside the admin layout and lists it in the menu', function (): void {
    $this->get(route('fastleo.pages.show', 'stats').'?n=42')
        ->assertOk()
        ->assertSee('Статистика')
        ->assertSee('<span data-n>42</span>', false);

    $this->get(route('fastleo.dashboard'))
        ->assertSee(route('fastleo.pages.show', 'stats'), false)
        ->assertSee('Отчёты');
});

it('builds the label and the address from the class name', function (): void {
    $page = Fastleo::resources()->findPage('secret');

    expect($page::label())->toBe('Secret')
        ->and($page::slug())->toBe('secret')
        ->and($page::icon())->toBe('file-text')
        ->and($page::group())->toBeNull()
        ->and($page::position())->toBe(0)
        ->and(Fastleo::resources()->pages())->toHaveKeys(['stats', 'secret']);
});

it('forbids a page to admins it is closed for, hides it in the menu and opens it for the right admin', function (): void {
    $this->get(route('fastleo.pages.show', 'secret'))->assertForbidden();
    $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.pages.show', 'secret'), false);

    $this->actingAs(admin(['email' => 'owner@example.com']), Fastleo::GUARD);
    $this->flushSession();
    $this->get(route('fastleo.pages.show', 'secret'))->assertOk();
});

it('answers 404 for an unknown page and sends a guest to the login form', function (): void {
    $this->get(route('fastleo.pages.show', 'nothing'))->assertNotFound();

    $this->app['auth']->guard(Fastleo::GUARD)->logout();
    $this->flushSession();
    $this->get(route('fastleo.pages.show', 'stats'))->assertRedirect(route('fastleo.login'));
});

describe('Fastleo::routes()', function (): void {
    beforeEach(function (): void {
        Fastleo::routes(function (): void {
            Route::get('stats/run', fn (): string => 'готово')->name('stats-run');
            Route::post('stats/save', fn (): string => 'сохранено')->name('stats-save');
        });
        Route::getRoutes()->refreshNameLookups();
    });

    it('registers site routes under the admin prefix with the admin protection', function (): void {
        expect(route('fastleo.pages.stats-run'))->toEndWith('/fastleo/pages/stats/run');

        $this->get(route('fastleo.pages.stats-run'))->assertOk()->assertSee('готово');
        $this->post(route('fastleo.pages.stats-save'))->assertOk()->assertSee('сохранено');
    });

    it('sends a guest to the login form and needs a csrf token', function (): void {
        $this->app['env'] = 'production';
        $this->post(route('fastleo.pages.stats-save'))->assertStatus(419);

        $this->app['env'] = 'testing';
        $this->app['auth']->guard(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->get(route('fastleo.pages.stats-run'))->assertRedirect(route('fastleo.login'));
    });

    it('sends the security headers and keeps the admin locale', function (): void {
        $this->get(route('fastleo.pages.stats-run'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });
});

describe('Fastleo::routes() bound to a page', function (): void {
    beforeEach(function (): void {
        Fastleo::routes(function (): void {
            Route::post('secret/run', fn (): string => 'запущено')->name('secret-run');
        }, page: SecretPage::class);
        Fastleo::routes(function (): void {
            Route::get('missing/run', fn (): string => 'нет страницы')->name('missing-run');
        }, page: Page::class);
        Route::getRoutes()->refreshNameLookups();
    });

    it('forbids the route to admins the page is closed for and lets the right admin in', function (): void {
        $this->post(route('fastleo.pages.secret-run'))->assertForbidden();

        $this->actingAs(admin(['email' => 'owner@example.com']), Fastleo::GUARD);
        $this->flushSession();
        $this->post(route('fastleo.pages.secret-run'))->assertOk()->assertSee('запущено');
    });

    it('answers 404 when the page is not registered', function (): void {
        $this->get(route('fastleo.pages.missing-run'))->assertNotFound();
    });
});

it('gives a page with its own template no report variables unless it sets its data itself', function (): void {
    $page = new class extends Page
    {
        #[Override]
        public function view(): string
        {
            return 'fastleo::pages.custom';
        }
    };

    expect($page->data(request()))->toBe([])
        ->and(new class extends Page {}->data(request()))->toHaveKeys(['page', 'items', 'filters', 'widgets', 'hasChart']);
});
