<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\SoftNetica\ClientData;
use Fastleo\SoftNetica\SoftNeticaPage;
use Fastleo\SoftNetica\SyncState;
use Fastleo\Widgets\SoftNetica;
use Illuminate\Support\Facades\Http;

/**
 * Встроенный дашборд (без своего класса сайта) при включённой функции SoftNetica.
 */
function useBuiltInDashboard(): void
{
    useFixtureResources('Menu');
    config(['fastleo.softnetica.enabled' => true]);
    Fastleo::resources()->flush();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->actingAs(admin(), Fastleo::GUARD);
    Http::fake();
});

describe('without a connection', function (): void {
    it('has neither the widget nor the section', function (): void {
        useBuiltInDashboard();
        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('data-widget="SoftNetica"', false)->assertDontSee(route('fastleo.pages.show', 'softnetica'), false);
        $this->get(route('fastleo.pages.show', 'softnetica'))->assertNotFound();

        expect(SoftNetica::make()->isVisible())->toBeFalse();
        Http::assertNothingSent();
    });

    it('hides everything again after the token is rejected', function (): void {
        useBuiltInDashboard();
        connectSoftNeticaWith();
        resolve(SyncState::class)->recordRejected(SOFTNETICA_TOKEN);

        $this->get(route('fastleo.dashboard'))->assertDontSee('data-widget="SoftNetica"', false);
        $this->get(route('fastleo.pages.show', 'softnetica'))->assertNotFound();
    });

    it('has no section when the function is switched off', function (): void {
        connectSoftNeticaWith();
        config(['fastleo.softnetica.enabled' => false]);
        Fastleo::resources()->flush();

        $this->get(route('fastleo.pages.show', 'softnetica'))->assertNotFound();
        $this->get(route('fastleo.dashboard'))->assertDontSee('data-widget="SoftNetica"', false);
    });

    it('is not offered by the search', function (): void {
        $this->getJson(route('fastleo.search', ['q' => 'SoftNetica']))->assertOk()->assertJsonMissing(['url' => route('fastleo.pages.show', 'softnetica')]);
    });
});

describe('connected, no data yet', function (): void {
    it('waits for the data', function (): void {
        useBuiltInDashboard();
        connectSoftNetica();

        $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('data-widget="SoftNetica"', false)->assertSee('Ожидаем данные от SoftNetica');
        $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk()->assertSee('Ожидаем данные от SoftNetica');
    });
});

describe('the widget', function (): void {
    it('shows the status, the backup of the night, the certificates and the next payment', function (): void {
        useBuiltInDashboard();
        connectSoftNeticaWith();

        $this->get(route('fastleo.dashboard'))
            ->assertOk()
            ->assertSee('data-widget="SoftNetica"', false)
            ->assertSee('Работает')
            ->assertSee('site.ru')
            ->assertSee('99,95')
            ->assertSee('осталось дней: 40')
            ->assertSee('осталось дней: 20')
            ->assertSee('Ближайший платёж')
            ->assertSee(ClientData::money(12000))
            ->assertSee('05.02.2026')
            ->assertSee('data-softnetica-backup', false)
            ->assertSee(route('fastleo.pages.show', 'softnetica'), false)
            ->assertDontSee('data-softnetica-stale', false);
    });

    it('shows a debt instead of the next payment', function (): void {
        useBuiltInDashboard();
        connectSoftNeticaWith(softneticaClient(['billing' => ['summary' => ['next' => ['due_at' => '2026-02-05', 'amount' => 1], 'overdue' => ['amount' => 24000, 'since' => '2026-01-10']], 'invoices' => [], 'billing_url' => null]]));

        $this->get(route('fastleo.dashboard'))->assertSee('data-softnetica-billing="debt"', false)->assertSee(ClientData::money(24000))->assertSee('10.01.2026')->assertDontSee('Ближайший платёж');
    });

    it('says there is no data for last night when the nights are old', function (): void {
        useBuiltInDashboard();
        $old = [['date' => now()->subDays(5)->toDateString(), 'copies' => ['main' => 'done']]];
        connectSoftNeticaWith(softneticaClient(['backup_nights' => ['targets' => [['target' => 'main', 'label' => 'Основная копия']], 'nights' => $old]]));

        $this->get(route('fastleo.dashboard'))->assertSee('нет данных за эту ночь');
    });

    it('marks the data older than half an hour as without connection', function (): void {
        useBuiltInDashboard();
        connectSoftNeticaWith();
        $this->travel(31)->minutes();

        $this->get(route('fastleo.dashboard'))->assertSee('data-softnetica-stale', false)->assertSee('Нет связи с SoftNetica')->assertSee('Работает');
    });

    it('is shown by Widget::make() in a custom dashboard and renders from the saved data', function (): void {
        connectSoftNeticaWith();

        $widget = SoftNetica::make();
        $payload = $widget->resolve();

        expect($widget->isVisible())->toBeTrue()
            ->and($widget->getTitle())->toBe('SoftNetica')
            ->and($payload['failed'])->toBeFalse()
            ->and($payload['data']->domain)->toBe('site.ru')
            ->and($payload['stale'])->toBeFalse();
    });

    it('respects visible(false)', function (): void {
        connectSoftNeticaWith();

        expect(SoftNetica::make()->visible(false)->isVisible())->toBeFalse();
    });
});

describe('the section', function (): void {
    it('is in the menu and shows all blocks', function (): void {
        connectSoftNeticaWith();

        $this->get(route('fastleo.profile.show'))->assertSee(route('fastleo.pages.show', 'softnetica'), false);

        $this->get(route('fastleo.pages.show', 'softnetica'))
            ->assertOk()
            ->assertSee('data-softnetica-block="site"', false)
            ->assertSee('data-softnetica-block="backups"', false)
            ->assertSee('data-softnetica-block="certificates"', false)
            ->assertSee('data-softnetica-block="security"', false)
            ->assertSee('data-softnetica-block="invoices"', false)
            ->assertSee('data-softnetica-block="reports"', false)
            ->assertSee('Основная копия')
            ->assertSee('Копия базы')
            ->assertSee('Проблем не найдено')
            ->assertSee('Январь 2026')
            ->assertSee('href="https://my.softnetica.com/reports/2026-02"', false)
            ->assertSee('href="https://my.softnetica.com/"', false)
            ->assertSee('data-softnetica-cabinet', false)
            ->assertSee('Проверка восстановления: автоматическая')
            ->assertSee('Успешно');
    });

    it('shows 14 nights with two copies each and at most 12 invoices', function (): void {
        connectSoftNeticaWith();

        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk()->getContent();

        expect(substr_count($html, 'data-softnetica-target'))->toBe(2)
            ->and(substr_count($html, 'data-copy='))->toBe(28)
            ->and(substr_count($html, 'data-softnetica-invoice'))->toBe(12)
            ->and(substr_count($html, 'data-softnetica-report'))->toBe(2)
            ->and($html)->not->toContain('Счёт № 13');
    });

    it('orders the nights by date by itself', function (): void {
        connectSoftNeticaWith();
        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->getContent();

        expect(strpos($html, now()->subDays(13)->format('d.m')))->toBeLessThan(strpos($html, now()->format('d.m').'</th>'));
    });

    it('shows a problem of the check and a site without a client', function (): void {
        connectSoftNeticaWith(softneticaClient(['security' => ['problem' => true, 'checked_at' => now()->toIso8601String()], 'billing' => null, 'reports' => [], 'cabinet_url' => null]));

        $this->get(route('fastleo.pages.show', 'softnetica'))
            ->assertOk()
            ->assertSee('Есть проблемы')
            ->assertSee('Сайт не привязан к клиенту SoftNetica')
            ->assertSee('Отчётов пока нет')
            ->assertDontSee('data-softnetica-cabinet', false);
    });

    it('marks the data older than half an hour as without connection', function (): void {
        connectSoftNeticaWith();
        $this->travel(31)->minutes();

        $this->get(route('fastleo.pages.show', 'softnetica'))->assertSee('data-softnetica-stale', false);
    });

    it('is open to every administrator and closed to guests', function (): void {
        connectSoftNeticaWith();

        $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk();

        auth(Fastleo::GUARD)->logout();
        $this->get(route('fastleo.pages.show', 'softnetica'))->assertRedirect(route('fastleo.login'));
    });

    it('is registered as a built-in page only while the function is on', function (): void {
        expect(Fastleo::resources()->pages())->toHaveKey('softnetica')
            ->and(Fastleo::resources()->findPage('softnetica'))->toBeInstanceOf(SoftNeticaPage::class);

        config(['fastleo.softnetica.enabled' => false]);
        Fastleo::resources()->flush();

        expect(Fastleo::resources()->pages())->not->toHaveKey('softnetica');
    });
});
