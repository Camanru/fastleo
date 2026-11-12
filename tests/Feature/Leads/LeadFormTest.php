<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Leads\FormToken;
use Fastleo\Leads\Lead;
use Fastleo\Leads\LeadNotifier;
use Fastleo\Leads\Leads;
use Fastleo\Leads\LeadStatus;
use Fastleo\Leads\StoreLeadRequest;
use Fastleo\Notifications\MessageNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Посетитель с постоянной сессией: метка времени формы привязана к её номеру.
    $this->sessionId = Str::random(40);
    $this->withCookie(config('session.cookie'), $this->sessionId);
});

afterEach(function (): void {
    Date::setTestNow();
    Fastleo::resolveOwnerUsing(null);
});

/**
 * Метка времени формы, показанной посетителю `$secondsAgo` секунд назад.
 */
function leadToken(int $secondsAgo = 60, ?string $session = null): string
{
    return Crypt::encryptString((now()->timestamp - $secondsAgo).'|'.($session ?? test()->sessionId));
}

describe('the site form', function (): void {
    it('has a tone and a label for every status', function (): void {
        foreach (LeadStatus::cases() as $status) {
            expect($status->label())->not->toStartWith('fastleo::')
                ->and($status->tone())->toBeIn(['success', 'warning', 'danger', 'info', 'neutral']);
        }
    });

    it('does not call a request without a token out of time: it is a bot, and the controller drops it before', function (): void {
        expect(StoreLeadRequest::create('/leads', 'POST', ['form_token' => ''])->isOutOfTime())->toBeFalse();
    });

    it('saves a lead from the form with the page it came from', function (): void {
        $this->withHeader('Referer', 'https://site.test/prices?from=menu')
            ->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'email' => 'ivan@example.com', 'message' => 'Хочу узнать цену', 'form_token' => leadToken()])
            ->assertRedirect()
            ->assertSessionHas('lead_sent');

        $lead = Lead::query()->sole();

        expect($lead->name)->toBe('Иван')
            ->and($lead->phone)->toBe('+7 900 000-00-00')
            ->and($lead->email)->toBe('ivan@example.com')
            ->and($lead->message)->toBe('Хочу узнать цену')
            ->and($lead->source)->toBe('/prices')
            ->and($lead->status)->toBe(LeadStatus::New)
            ->and($lead->read_at)->toBeNull()
            ->and($lead->site)->toBeNull();
    });

    it('takes only name, phone, email and message from the visitor', function (): void {
        $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'status' => 'done', 'site' => 'x', 'read_at' => now()->toDateTimeString(), 'form_token' => leadToken()]);

        $lead = Lead::query()->sole();

        expect($lead->status)->toBe(LeadStatus::New)
            ->and($lead->site)->toBeNull()
            ->and($lead->read_at)->toBeNull();
    });

    it('measures the length of an email before it parses it', function (): void {
        $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'email' => str_repeat('a', 300), 'form_token' => leadToken()])
            ->assertSessionHasErrors(['email']);

        expect(session('errors')->get('email'))->toHaveCount(1);
    });

    it('checks the fields of the form', function (): void {
        $this->post('/leads', ['name' => '', 'phone' => 'abc', 'email' => 'не почта', 'form_token' => leadToken()])
            ->assertSessionHasErrors(['name', 'phone', 'email']);

        expect(Lead::query()->count())->toBe(0);
    });

    it('rejects text with a NUL byte that PostgreSQL would answer with a 500', function (): void {
        $this->post('/leads', ['name' => "Ив\0ан", 'phone' => '+7 900 000-00-00', 'form_token' => leadToken()])->assertSessionHasErrors(['name']);
    });

    it('drops a lead from a bot silently: filled trap, no token or a forged one', function (array $extra): void {
        $this->post('/leads', ['name' => 'Бот', 'phone' => '+7 900 000-00-00', ...$extra])
            ->assertRedirect()
            ->assertSessionHas('lead_sent');

        expect(Lead::query()->count())->toBe(0);
    })->with([
        // Метка настоящая: бота отсеивает только заполненная ловушка.
        'filled trap' => [fn (): array => ['leave_empty' => 'http://spam.example', 'form_token' => leadToken()]],
        'no token' => [[]],
        'forged token' => [['form_token' => 'подделка']],
    ]);

    it('shows an honest visitor an error when the form is sent too fast, is stale or comes from another session', function (int $secondsAgo, ?string $session): void {
        $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_token' => leadToken($secondsAgo, $session)])
            ->assertSessionHasErrors(['form_token']);

        expect(Lead::query()->count())->toBe(0);
    })->with([
        'too fast' => [1, null],
        'a day old' => [86401, null],
        'another session' => [60, 'ssssssssssssssssssssssssssssssssssssssss'],
    ]);

    it('gives out a token that is bound to the session and carries the time it was shown', function (): void {
        Date::setTestNow('2026-11-10 12:00:00');
        session()->setId($this->sessionId);

        $token = FormToken::read(FormToken::make());

        expect($token)->toBe(['shown_at' => now()->timestamp, 'session' => $this->sessionId])
            ->and(FormToken::read('мусор'))->toBeNull()
            ->and(FormToken::read(null))->toBeNull();
    });

    it('gives every shown form its own token even within one second and one session', function (): void {
        Date::setTestNow('2026-11-10 12:00:00');
        session()->setId($this->sessionId);

        expect(Crypt::decryptString(FormToken::make()))->not->toBe(Crypt::decryptString(FormToken::make()));
    });

    describe('several forms on one page', function (): void {
        it('takes the name of the form and the anchor from the hidden fields and goes back to that form', function (): void {
            $this->withHeader('Referer', 'https://site.test/?topic=Тариф')
                ->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_anchor' => 'hero', 'form' => "  Тариф\n  Бизнес ", 'form_token' => leadToken()])
                ->assertRedirect('https://site.test/?topic=Тариф#form-hero')
                ->assertSessionHas('lead_sent', true)
                ->assertSessionHas('lead_sent_anchor', 'hero');

            expect(Lead::query()->sole()->form)->toBe('Тариф Бизнес');
        });

        it('cuts the name of the form to a hundred characters and leaves it empty without one', function (): void {
            $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form' => str_repeat('я', 150), 'form_token' => leadToken()])->assertRedirect();
            $this->post('/leads', ['name' => 'Пётр', 'phone' => '+7 900 000-00-00', 'form' => '   ', 'form_token' => leadToken()])->assertRedirect();

            expect(Lead::query()->where('name', 'Иван')->sole()->form)->toBe(str_repeat('я', 100))
                ->and(Lead::query()->where('name', 'Пётр')->sole()->form)->toBeNull();
        });

        it('cuts invisible and direction-changing characters out of the name of the form', function (): void {
            $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form' => "Тариф\u{202E}\u{200B} Бизнес\u{2028}\n(PRO)\t", 'form_token' => leadToken()])->assertRedirect();

            expect(Lead::query()->sole()->form)->toBe('Тариф Бизнес (PRO)')
                ->and(Leads::formName(['x']))->toBeNull()
                ->and(Leads::formName(" \u{200B} "))->toBeNull();
        });

        it('refuses a name of the form that is far too long instead of keeping it in the session', function (): void {
            $this->post('/leads', ['name' => '', 'phone' => '+7 900 000-00-00', 'form' => str_repeat('я', 501), 'form_token' => leadToken()])->assertSessionHasErrors(['form']);
        });

        it('rejects a name of the form with a NUL byte', function (): void {
            $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form' => "а\0б", 'form_token' => leadToken()])->assertSessionHasErrors(['form']);
        });

        it('drops a broken anchor: no fragment, no anchor in the session', function (string $anchor): void {
            $this->withHeader('Referer', 'https://site.test/')
                ->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_anchor' => $anchor, 'form_token' => leadToken()])
                ->assertRedirect('https://site.test/')
                ->assertSessionMissing('lead_sent_anchor');
        })->with(["end\nx", 'Верх', '1a', 'a b', 'a#b', str_repeat('a', 41)]);

        it('brings the visitor back to the form and keeps the errors in the bag of that form', function (): void {
            $this->withHeader('Referer', 'https://site.test/')
                ->post('/leads', ['name' => '', 'phone' => 'abc', 'form_anchor' => 'final', '_error_bag' => 'evil', 'form_token' => leadToken()])
                ->assertRedirect('https://site.test/#form-final')
                ->assertSessionHasErrorsIn('lead_final', ['name', 'phone'])
                ->assertSessionHasInput('form_anchor', 'final');

            expect(session('errors')->hasBag('default'))->toBeFalse()
                ->and(session('errors')->hasBag('evil'))->toBeFalse();
        });

        it('keeps the error of the email of a form with an anchor in the bag of that form, and takes no anchor that is not a string', function (): void {
            $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'email' => 'не почта', 'form_anchor' => 'hero', 'form_token' => leadToken()])
                ->assertSessionHasErrorsIn('lead_hero', ['email']);

            $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_anchor' => ['x'], 'form_token' => leadToken()])
                ->assertSessionMissing('lead_sent_anchor');
        });

        it('brings the visitor back to the form when it was sent too fast', function (): void {
            $this->withHeader('Referer', 'https://site.test/')
                ->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_anchor' => 'hero', 'form_token' => leadToken(1)])
                ->assertRedirect('https://site.test/#form-hero')
                ->assertSessionHasErrorsIn('lead_hero', ['form_token']);
        });

        it('thanks a bot for the form it filled in as well, with the fragment, and saves nothing', function (): void {
            $this->withHeader('Referer', 'https://site.test/')
                ->post('/leads', ['name' => 'Бот', 'phone' => '+7 900 000-00-00', 'form_anchor' => 'hero', 'leave_empty' => 'x', 'form_token' => leadToken()])
                ->assertRedirect('https://site.test/#form-hero')
                ->assertSessionHas('lead_sent_anchor', 'hero');

            expect(Lead::query()->count())->toBe(0);
        });
    });

    it('stops one address after five leads in ten minutes', function (): void {
        foreach (range(1, 5) as $_) {
            $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_token' => leadToken()])->assertRedirect();
        }

        $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_token' => leadToken()])->assertStatus(429);

        expect(Lead::query()->count())->toBe(5);
    });

    it('notifies the administrators about a new lead in one line with a link to the record', function (): void {
        Notification::fake();
        config(['fastleo.notifications.bell' => true]);
        $admin = admin();

        $this->post('/leads', ['name' => "Иван\nhttps://evil.example www.pay.example tg://x evil.com J.Smith a。io", 'phone' => '+7 900 000-00-00', 'message' => 'секрет', 'form_token' => leadToken()]);

        $lead = Lead::query()->sole();

        Notification::assertSentTo($admin, MessageNotification::class, function (MessageNotification $notification) use ($admin, $lead): bool {
            $message = $notification->toFastleo($admin)->toArray();

            return $message['title'] === 'Новая заявка'
                && $message['body'] === 'Иван evil. com J. Smith a. io, +7 900 000-00-00'
                && str_contains((string) $message['body'], '+7 900 000-00-00')
                && ! str_contains((string) $message['body'], 'секрет')
                && $message['url'] === Fastleo::recordUrl($lead, false);
        });
    });

    it('notifies no more than twenty times a minute, but keeps every lead', function (): void {
        Notification::fake();
        config(['fastleo.notifications.bell' => true]);
        $admin = admin();
        RateLimiter::clear('leads-notify');

        foreach (range(1, 21) as $_) {
            LeadNotifier::send(Lead::query()->create(['name' => 'А', 'phone' => '+7 900 000-00-00', 'status' => LeadStatus::New]));
        }

        Notification::assertSentToTimes($admin, MessageNotification::class, 20);
    });

    it('does not show the visitor an error when the notification fails', function (): void {
        Bus::fake();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('почта недоступна'));
        config(['fastleo.notifications.bell' => true]);
        admin();

        $this->post('/leads', ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_token' => leadToken()])->assertSessionHas('lead_sent');

        expect(Lead::query()->count())->toBe(1);
    });
});
