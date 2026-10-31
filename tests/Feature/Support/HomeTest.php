<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\Home;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Menu');
    config(['fastleo.logs.enabled' => false]);
});

describe('the start page', function (): void {
    it('is the dashboard unless the config says otherwise', function (): void {
        expect(Home::url())->toBe(route('fastleo.dashboard'));
    });

    it('is the section named in the config: login, the logo and an already signed-in visit lead there', function (): void {
        config(['fastleo.home' => 'news']);
        $admin = admin(['email' => 'admin@example.com']);

        login(['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect(route('fastleo.resources.index', 'news'));

        $this->actingAs($admin, Fastleo::GUARD);

        $this->get(route('fastleo.login'))->assertRedirect(route('fastleo.resources.index', 'news'));
        $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('href="'.route('fastleo.resources.index', 'news').'"', false);
    });

    it('is a page of the site when the config names one', function (): void {
        useFixtureResources('Pages');
        config(['fastleo.home' => 'stats']);
        $this->actingAs(admin(), Fastleo::GUARD);

        expect(Home::url())->toBe(route('fastleo.pages.show', 'stats'));
    });

    it('stays on the dashboard when the section is unknown or closed to this admin', function (): void {
        $this->actingAs(admin(), Fastleo::GUARD);

        config(['fastleo.home' => 'nothing']);
        expect(Home::url())->toBe(route('fastleo.dashboard'));

        config(['fastleo.home' => 'news']);
        Gate::policy(Category::class, HiddenCategoryPolicy::class);
        expect(Home::url())->toBe(route('fastleo.dashboard'));
    });

    it('stays on the dashboard when the page refuses this admin', function (): void {
        useFixtureResources('Pages');
        config(['fastleo.home' => 'secret']);
        $this->actingAs(admin(), Fastleo::GUARD);

        expect(Home::url())->toBe(route('fastleo.dashboard'));
    });

    it('does not break the way back to the page the admin asked for before login', function (): void {
        config(['fastleo.home' => 'news']);
        admin(['email' => 'admin@example.com']);
        $target = route('fastleo.resources.index', 'documents');

        $this->withSession(['url.intended' => $target])->post(route('fastleo.login.store'), ['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect($target);
    });
});

describe('the menu', function (): void {
    beforeEach(fn () => $this->actingAs(admin(), Fastleo::GUARD));

    it('has the dashboard item by default', function (): void {
        $this->get(route('fastleo.resources.index', 'news'))->assertOk()->assertSee('Дашборд');
    });

    it('drops the dashboard item when menu.dashboard is off, while the page itself still opens', function (): void {
        config(['fastleo.menu.dashboard' => false]);

        $this->get(route('fastleo.resources.index', 'news'))->assertOk()->assertDontSee('Дашборд');
        $this->get(route('fastleo.dashboard'))->assertOk();
    });

    it('starts collapsed or expanded as menu.collapsed says, and by the width of the window otherwise', function (): void {
        $page = fn (): string => $this->get(route('fastleo.resources.index', 'news'))->getContent();

        expect($page())->toContain('sidebar === null ? window.innerWidth < 1280 :');

        config(['fastleo.menu.collapsed' => true]);
        expect($page())->toContain('sidebar === null ? true :');

        config(['fastleo.menu.collapsed' => false]);
        expect($page())->toContain('sidebar === null ? false :');
    });
});
