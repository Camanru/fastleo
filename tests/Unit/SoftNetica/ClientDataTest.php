<?php

declare(strict_types=1);

use Fastleo\SoftNetica\ClientData;

describe('strings and types', function (): void {
    it('reads the full answer', function (): void {
        $data = ClientData::from(softneticaClient());

        expect($data->domain)->toBe('site.ru')
            ->and($data->status)->toBe(['tone' => 'ok', 'label' => 'Работает'])
            ->and($data->uptime)->toBe(99.95)
            ->and($data->ssl['days'])->toBe(40)
            ->and($data->domainExpiry)->toMatchArray(['days' => 20, 'tone' => 'warn', 'paid_by_client' => true])
            ->and($data->security['problem'])->toBeFalse()
            ->and($data->targets)->toBe([['target' => 'main', 'label' => 'Основная копия'], ['target' => 'db', 'label' => 'Копия базы']])
            ->and($data->restore['automatic']['result'])->toBe('Успешно')
            ->and($data->restore['manual'])->toBeNull()
            ->and($data->billing['next'])->toMatchArray(['amount' => 12000])
            ->and($data->cabinetUrl)->toBe('https://my.softnetica.com/');
    });

    it('survives an empty answer, nulls and values of wrong types', function (mixed $client): void {
        $data = ClientData::from($client);

        expect($data->status['tone'])->toBe('mute')
            ->and($data->nights)->toBe([])
            ->and($data->reports)->toBe([])
            ->and($data->billing)->toBeNull();
    })->with([
        'empty' => [[]],
        'nulls' => [['domain' => null, 'status' => null, 'uptime_30d' => null, 'backup' => null, 'ssl' => null, 'domain_expiry' => null, 'security' => null, 'backup_nights' => null, 'restore' => null, 'billing' => null, 'reports' => null, 'cabinet_url' => null]],
        'strings instead of arrays' => [['status' => 'ok', 'backup' => 'x', 'ssl' => 'x', 'backup_nights' => 'x', 'billing' => 'x', 'reports' => 'x', 'restore' => 'x', 'security' => 'x']],
        'arrays instead of strings' => [['domain' => ['a'], 'status' => ['tone' => ['x'], 'label' => ['y']], 'uptime_30d' => 'много', 'cabinet_url' => ['https://my.softnetica.com/']]],
    ]);

    it('cleans strings: control characters, spaces, length, numbers', function (): void {
        $data = ClientData::from(['domain' => "  si\x00te\x07.ru\n\u{202E} ", 'status' => ['tone' => 'ok', 'label' => str_repeat('я', 300)], 'ssl' => ['expires_at' => '2026-03-12', 'days_left' => '15', 'tone' => 'ok']]);

        expect($data->domain)->toBe('si te .ru')
            ->and(mb_strlen((string) $data->status['label']))->toBeLessThanOrEqual(100)
            ->and($data->ssl['days'])->toBe(15);

        expect(ClientData::from(['domain' => 12345])->domain)->toBe('12345');
    });

    it('turns unknown colors into mute', function (mixed $tone): void {
        expect(ClientData::from(['status' => ['tone' => $tone]])->status['tone'])->toBe('mute');
    })->with(['unknown' => ['red'], 'markup' => ['ok" onmouseover="x'], 'number' => [1], 'array' => [['ok']], 'upper case' => ['OK']]);

    it('keeps the four allowed colors', function (string $tone): void {
        expect(ClientData::from(['status' => ['tone' => $tone]])->status['tone'])->toBe($tone);
    })->with(['ok', 'warn', 'crit', 'mute']);

    it('limits the uptime to 0..100 percent and rejects non-numbers', function (): void {
        expect(ClientData::from(['uptime_30d' => 150])->uptime)->toBe(100.0)
            ->and(ClientData::from(['uptime_30d' => -5])->uptime)->toBe(0.0)
            ->and(ClientData::from(['uptime_30d' => '99'])->uptime)->toBeNull();
    });

    it('drops broken dates', function (mixed $date): void {
        expect(ClientData::from(['ssl' => ['expires_at' => $date]])->ssl['at'])->toBeNull();
    })->with(['not a date' => ['вчера'], 'number' => [123], 'too long' => [str_repeat('1', 50)], 'impossible' => ['2026-13-45'], 'array' => [['2026-01-01']]]);

    it('drops a date with a time that cannot exist instead of failing', function (): void {
        expect(ClientData::from(['ssl' => ['expires_at' => '2026-13-45T10:00:00+00:00']])->ssl['at'])->toBeNull();
    });

    it('turns the colors of SoftNetica into the tones of the badge', function (string $tone, string $badge): void {
        expect(ClientData::badgeTone($tone))->toBe($badge);
    })->with([['ok', 'success'], ['warn', 'warning'], ['crit', 'danger'], ['mute', 'neutral']]);

    it('shows a date with time in the admin time zone', function (): void {
        config(['fastleo.timezone' => 'Asia/Vladivostok']);

        $at = ClientData::from(['ssl' => ['expires_at' => '2026-03-12T20:30:00+00:00']])->ssl['at'];

        expect(ClientData::show($at, true))->toBe('13.03.2026 06:30')
            ->and(ClientData::show($at))->toBe('13.03.2026')
            ->and(ClientData::show(null))->toBe('нет данных')
            ->and(ClientData::money(1234567))->toBe("1\u{00A0}234\u{00A0}567\u{00A0}₽")
            ->and(ClientData::money(null))->toBe('нет данных');
    });
});

describe('nights, invoices and reports', function (): void {
    it('sorts the nights by date itself and keeps the last 14', function (): void {
        $nights = [];

        for ($i = 0; $i < 20; $i++) {
            $nights[] = ['date' => now()->subDays($i)->toDateString(), 'copies' => ['main' => 'done']];
        }

        $data = ClientData::from(['backup_nights' => ['targets' => [['target' => 'main', 'label' => 'Основная копия']], 'nights' => $nights]]);

        expect($data->nights)->toHaveCount(14)
            ->and($data->nights[0]['date']->toDateString())->toBe(now()->subDays(13)->toDateString())
            ->and($data->lastNight()['date']->toDateString())->toBe(now()->toDateString())
            ->and($data->lastNightIsFresh())->toBeTrue();
    });

    it('ignores nights with a bad date and unknown states', function (): void {
        $data = ClientData::from(['backup_nights' => ['targets' => [['target' => 'main', 'label' => 'A'], ['target' => 'main', 'label' => 'B'], ['target' => '', 'label' => 'C']], 'nights' => [
            ['date' => 'вчера', 'copies' => ['main' => 'done']],
            ['date' => '2026-01-02', 'copies' => ['main' => 'exploded', 'other' => 'done']],
            'junk',
        ]]]);

        expect($data->targets)->toBe([['target' => 'main', 'label' => 'A']])
            ->and($data->nights)->toHaveCount(1)
            ->and($data->nights[0]['copies'])->toBe(['main' => null]);
    });

    it('tells how the night went', function (array $copies, string $tone, string $summary): void {
        $night = ['date' => now()->toImmutable(), 'copies' => $copies];

        expect(ClientData::nightTone($night))->toBe($tone)->and(ClientData::nightSummary($night))->toBe($summary);
    })->with([
        'all done' => [['a' => 'done', 'b' => 'done'], 'ok', '2 / 2'],
        'one partial' => [['a' => 'done', 'b' => 'partial'], 'warn', '1 / 2'],
        'one missed' => [['a' => 'missed', 'b' => 'partial'], 'crit', '0 / 2'],
        'waiting' => [['a' => 'done', 'b' => 'pending'], 'mute', '1 / 2'],
        'unknown' => [['a' => 'done', 'b' => null], 'mute', '1 / 2'],
        'none' => [[], 'mute', '0 / 0'],
    ]);

    it('says an old night is not fresh', function (): void {
        $data = ClientData::from(['backup_nights' => ['targets' => [['target' => 'main', 'label' => 'A']], 'nights' => [['date' => now()->subDays(3)->toDateString(), 'copies' => ['main' => 'done']]]]]);

        expect($data->lastNightIsFresh())->toBeFalse()
            ->and(ClientData::from([])->lastNight())->toBeNull()
            ->and(ClientData::from([])->lastNightIsFresh())->toBeFalse();
    });

    it('keeps at most 12 invoices with their payments and no secrets from unknown fields', function (): void {
        $data = ClientData::from(softneticaClient());

        expect($data->billing['invoices'])->toHaveCount(12)
            ->and($data->billing['invoices'][0])->toMatchArray(['id' => '1', 'title' => 'Счёт № 1', 'amount' => 12000, 'remaining' => 12000, 'tone' => 'warn', 'act_available' => true])
            ->and($data->billing['invoices'][0]['payments'][0])->toMatchArray(['amount' => 12000, 'method' => 'Карта'])
            ->and($data->billing['invoices'][0]['url'])->toBe('https://my.softnetica.com/invoices/1');
    });

    it('takes amounts as whole rubles only', function (): void {
        $invoice = ClientData::from(['billing' => ['invoices' => [['amount' => '1200', 'remaining_amount' => 'много', 'due_at' => 'скоро']]]])->billing['invoices'][0];

        expect($invoice['amount'])->toBe(1200)->and($invoice['remaining'])->toBeNull()->and($invoice['due_at'])->toBeNull();
    });

    it('keeps at most 12 reports, newest first, and drops bad months', function (): void {
        $reports = [];

        for ($m = 1; $m <= 15; $m++) {
            $reports[] = ['month' => sprintf('2025-%02d', $m), 'current' => false, 'url' => null];
        }

        $reports[] = ['month' => 'вчера'];

        $months = array_column(ClientData::from(['reports' => $reports])->reports, 'month');

        expect($months)->toHaveCount(12)->and($months[0])->toBe('2025-12')->and($months)->not->toContain('2025-13');
    });

    it('names the month in Russian', function (): void {
        expect(ClientData::from(['reports' => [['month' => '2026-03']]])->reports[0]['label'])->toBe('Март 2026');
    });
});

describe('links', function (): void {
    it('accepts only https links to the cabinet host', function (mixed $url, ?string $expected): void {
        expect(ClientData::link($url))->toBe($expected);
    })->with([
        'cabinet' => ['https://my.softnetica.com/billing', 'https://my.softnetica.com/billing'],
        'with query and fragment' => ['https://my.softnetica.com/i/5?x=1#a', 'https://my.softnetica.com/i/5?x=1#a'],
        'host in upper case' => ['https://MY.SOFTNETICA.COM/x', 'https://MY.SOFTNETICA.COM/x'],
        'other host' => ['https://evil.example/x', null],
        'panel host' => ['https://panel.softnetica.com/x', null],
        'subdomain trick' => ['https://my.softnetica.com.evil.example/', null],
        'login trick' => ['https://my.softnetica.com@evil.example/', null],
        'backslash trick' => ['https://evil.example\\@my.softnetica.com/', null],
        'port' => ['https://my.softnetica.com:8443/x', null],
        'http' => ['http://my.softnetica.com/x', null],
        'protocol-relative' => ['//my.softnetica.com/x', null],
        'javascript' => ['javascript:alert(1)', null],
        'data' => ['data:text/html,<script>alert(1)</script>', null],
        'space' => ['https://my.softnetica.com/a b', null],
        'newline' => ["https://my.softnetica.com/a\nb", null],
        'non ascii' => ['https://my.softnetica.com/привет', null],
        'relative' => ['/billing', null],
        'empty' => ['', null],
        'null' => [null, null],
        'array' => [['https://my.softnetica.com/'], null],
        'too long' => ['https://my.softnetica.com/'.str_repeat('a', 2000), null],
    ]);

    it('takes the hosts from the config', function (): void {
        config(['fastleo.softnetica.cabinet_hosts' => ['cabinet.test']]);

        expect(ClientData::link('https://cabinet.test/x'))->toBe('https://cabinet.test/x')
            ->and(ClientData::link('https://my.softnetica.com/x'))->toBeNull();
    });
});
