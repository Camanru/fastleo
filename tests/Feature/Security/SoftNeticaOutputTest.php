<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Http;

/**
 * Ответ SoftNetica, в котором каждая строка — разметка и скрипт, а ссылки ведут на чужие адреса.
 *
 * @return array<string, mixed>
 */
function hostileClient(): array
{
    $xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
    $evil = 'https://evil.example/steal';

    return softneticaClient([
        'domain' => $xss,
        'status' => ['tone' => 'ok" onmouseover="alert(1)', 'label' => $xss],
        'backup' => ['last_success_at' => $xss, 'tone' => '"><script>'],
        'ssl' => ['expires_at' => $xss, 'days_left' => $xss, 'tone' => 'evil'],
        'domain_expiry' => ['expires_on' => $xss, 'days_left' => '<b>1</b>', 'tone' => 'x y z', 'paid_by_client' => $xss],
        'security' => ['problem' => $xss, 'checked_at' => $xss],
        'backup_nights' => ['targets' => [['target' => 'main', 'label' => $xss]], 'nights' => [['date' => now()->toDateString(), 'copies' => ['main' => $xss]]]],
        'restore' => ['automatic' => ['tested_on' => $xss, 'result' => $xss, 'tone' => $xss], 'manual' => ['result' => $xss]],
        'billing' => [
            'summary' => ['next' => ['due_at' => $xss, 'amount' => $xss], 'overdue' => null],
            'invoices' => [['id' => $xss, 'title' => $xss, 'status_label' => $xss, 'tone' => '"><script>alert(1)</script>', 'amount' => $xss, 'url' => 'javascript:alert(document.cookie)',
                'payments' => [['paid_at' => $xss, 'amount' => $xss, 'method_label' => $xss]]]],
            'billing_url' => $evil,
        ],
        'reports' => [['month' => '2026-01', 'current' => $xss, 'url' => 'https://my.softnetica.com@evil.example/'], ['month' => '2026-02', 'url' => 'http://my.softnetica.com/plain']],
        'cabinet_url' => 'https://evil.example\\@my.softnetica.com/',
        'unknown_field' => $xss,
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $this->actingAs(admin(), Fastleo::GUARD);
});

describe('data of an external service on the page', function (): void {
    it('never prints raw markup, scripts or event handlers', function (): void {
        connectSoftNeticaWith(hostileClient());

        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk()->getContent();
        $block = substr($html, (int) strpos($html, 'data-softnetica-block="site"'));

        expect($block)->not->toContain('<script>alert')
            ->and($block)->not->toContain('<img src=x')
            ->and($block)->not->toContain('" onmouseover="')
            ->and($block)->toContain('&lt;script&gt;');
    });

    it('drops links to other hosts and unsafe schemes', function (): void {
        connectSoftNeticaWith(hostileClient());

        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk()->getContent();

        expect($html)->not->toContain('evil.example')
            ->and($html)->not->toContain('javascript:alert')
            ->and($html)->not->toContain('http://my.softnetica.com')
            ->and($html)->not->toContain('data-softnetica-cabinet');
    });

    it('prints the allowed links and nothing else as a link target', function (): void {
        connectSoftNeticaWith();

        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk()->getContent();
        preg_match_all('/data-softnetica-block.*?<\/section>/s', $html, $blocks);
        preg_match_all('/<a\b[^>]*\bhref="([^"]+)"/', implode('', $blocks[0]), $links);

        expect($links[1])->not->toBeEmpty();

        foreach ($links[1] as $href) {
            expect($href)->toStartWith('https://my.softnetica.com/');
        }
    });

    it('opens external links without giving the opener to the other site', function (): void {
        connectSoftNeticaWith();

        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->getContent();
        preg_match_all('/<a\b[^>]*href="https:\/\/my\.softnetica\.com[^>]*>/', $html, $anchors);

        expect($anchors[0])->not->toBeEmpty();

        foreach ($anchors[0] as $anchor) {
            expect($anchor)->toContain('rel="noopener noreferrer"');
        }
    });

    it('uses only the allowed colors for classes', function (): void {
        useFixtureResources('Menu');
        config(['fastleo.softnetica.enabled' => true]);
        Fastleo::resources()->flush();
        connectSoftNeticaWith(hostileClient());

        $html = $this->get(route('fastleo.pages.show', 'softnetica'))->getContent();

        expect($html)->not->toContain('evil"')->not->toContain('x y z');
    });

    it('survives an answer where every value has the wrong type', function (): void {
        connectSoftNeticaWith(array_fill_keys(['domain', 'status', 'uptime_30d', 'backup', 'ssl', 'domain_expiry', 'security', 'backup_nights', 'restore', 'billing', 'reports', 'cabinet_url'], 12345));

        $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk()->assertSee('нет данных');
    });

    it('survives an answer with arrays in place of values', function (): void {
        connectSoftNeticaWith(array_fill_keys(['domain', 'status', 'uptime_30d', 'backup', 'ssl', 'domain_expiry', 'security', 'backup_nights', 'restore', 'billing', 'reports', 'cabinet_url'], [[], [[]], ['x' => ['y']]]));

        $this->get(route('fastleo.pages.show', 'softnetica'))->assertOk();
    });
});

describe('data of an external service in the widget', function (): void {
    it('is escaped and has no foreign links', function (): void {
        useFixtureResources('Menu');
        config(['fastleo.softnetica.enabled' => true]);
        Fastleo::resources()->flush();
        connectSoftNeticaWith(hostileClient());

        $html = $this->get(route('fastleo.dashboard'))->assertOk()->getContent();
        $widget = substr($html, (int) strpos($html, 'data-widget="SoftNetica"'));

        expect($widget)->not->toContain('<script>alert')
            ->and($widget)->not->toContain('<img')
            ->and($widget)->not->toContain('" onmouseover="')
            ->and($widget)->not->toContain('evil.example')
            ->and($widget)->not->toContain('javascript:');
    });
});

describe('the access to the section', function (): void {
    it('is closed to guests and does not leak whether the site is connected', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->get(route('fastleo.pages.show', 'softnetica'))->assertRedirect(route('fastleo.login'));

        connectSoftNeticaWith();
        $this->get(route('fastleo.pages.show', 'softnetica'))->assertRedirect(route('fastleo.login'));
    });

    it('does not let the check and the disconnect through GET', function (): void {
        expect($this->get('/fastleo/softnetica/check')->getStatusCode())->toBeIn([404, 405])
            ->and($this->get('/fastleo/softnetica/disconnect')->getStatusCode())->toBeIn([404, 405]);
    });
});
