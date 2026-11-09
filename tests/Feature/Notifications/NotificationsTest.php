<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Notifications\FastleoChannel;
use Fastleo\Notifications\FastleoMessage;
use Fastleo\Notifications\MessageMailNotification;
use Fastleo\Notifications\MessageNotification;
use Fastleo\Notifications\SendAdminMessage;
use Fastleo\Support\Menu;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->me = admin(['name' => 'Анна', 'email' => 'anna@example.com']);
    $this->actingAs($this->me, Fastleo::GUARD);
    Fastleo::resolveAdminsUsing(null);
});

afterEach(function (): void {
    Fastleo::resolveAdminsUsing(null);
});

describe('message', function (): void {
    it('keeps only safe links', function (?string $url, ?string $expected): void {
        expect(FastleoMessage::make('Заголовок')->url($url)->toArray()['url'])->toBe($expected);
    })->with([
        'https' => ['https://site.ru/a?b=1', 'https://site.ru/a?b=1'],
        'http' => ['http://site.ru', 'http://site.ru'],
        'relative' => ['/fastleo/leads/7', '/fastleo/leads/7'],
        'javascript' => ['javascript:alert(1)', null],
        'data' => ['data:text/html,<script>', null],
        'protocol-relative' => ['//evil.example', null],
        'backslash' => ['/\\evil.example', null],
        'tab hides the second slash' => ["/\t/evil.example", null],
        'plain word' => ['evil', null],
        'null' => [null, null],
    ]);

    it('limits the length and falls back for a wrong icon or level', function (): void {
        $data = FastleoMessage::make(str_repeat('а', 300))->body(str_repeat('б', 900))->icon('<script>')->level('critical')->toArray();

        expect(mb_strlen($data['title']))->toBe(150)
            ->and(mb_strlen($data['body']))->toBe(500)
            ->and($data['icon'])->toBe('bell')
            ->and($data['level'])->toBe('info');
        expect(FastleoMessage::make('x')->icon('inbox')->level('danger')->toArray())->toMatchArray(['icon' => 'inbox', 'level' => 'danger']);
    });

    it('builds the Telegram text with a link from APP_URL, not from the host of the request', function (): void {
        config(['app.url' => 'https://site.test']);

        $text = FastleoMessage::make('Новая заявка')->body('Иван')->url('/fastleo/leads/7')->toText();

        expect($text)->toBe("Новая заявка\nИван\nhttps://site.test/fastleo/leads/7")
            ->and(FastleoMessage::make('Просто')->toText())->toBe('Просто')
            ->and(FastleoMessage::make('x')->url('https://other.test/x')->toText())->toBe("x\nhttps://other.test/x");
    });

    it('builds the links of the text and of the letter on the domain of the admin when the site has a separate one', function (): void {
        config(['app.url' => 'https://site.test', 'fastleo.domain' => 'admin.site.test']);
        $message = FastleoMessage::make('Заявка')->url('/fastleo/leads/7');

        expect($message->toText())->toBe("Заявка\nhttps://admin.site.test/fastleo/leads/7")
            ->and($message->toMail()->actionUrl)->toBe('https://admin.site.test/fastleo/leads/7');
    });
});

describe('mail copy', function (): void {
    it('sends no mail until it is switched on', function (): void {
        NotificationFacade::fake();

        Fastleo::notifyAdmins('Привет');

        NotificationFacade::assertSentOnDemandTimes(MessageMailNotification::class, 0);
        NotificationFacade::assertSentTo($this->me, MessageNotification::class);
    });

    it('mails every admin who has an address and still fills the bell', function (): void {
        config(['fastleo.notifications.mail' => true]);
        $boris = admin(['email' => 'boris@example.com']);
        $nobody = User::factory()->make(['email' => '']);
        Fastleo::resolveAdminsUsing(fn (): array => [$this->me, $boris, $nobody]);
        NotificationFacade::fake();

        Fastleo::notifyAdmins('Новая заявка');

        NotificationFacade::assertSentOnDemandTimes(MessageMailNotification::class, 2);
        NotificationFacade::assertSentOnDemand(MessageMailNotification::class, fn (MessageMailNotification $mail, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'anna@example.com' && $channels === ['mail']);
        NotificationFacade::assertSentOnDemand(MessageMailNotification::class, fn (MessageMailNotification $mail, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'boris@example.com');
        NotificationFacade::assertSentTo([$this->me, $boris, $nobody], MessageNotification::class);
    });

    it('really delivers the letter with the text and the button', function (): void {
        config(['fastleo.notifications.mail' => true, 'mail.default' => 'array', 'app.url' => 'https://site.test']);

        Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body('Иван')->url('/fastleo/leads/7'));

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $mail = $messages[0]->getOriginalMessage();

        expect($messages)->toHaveCount(1)
            ->and($mail->getTo()[0]->getAddress())->toBe('anna@example.com')
            ->and($mail->getSubject())->toBe('Новая заявка')
            ->and($mail->getHtmlBody())->toContain('Иван')->toContain('https://site.test/fastleo/leads/7');
    });

    it('does not let a visitor put his own link or picture into the letter to the admin', function (): void {
        $payload = '[Подтвердите оплату](https://evil.example/pay) ![](https://evil.example/t.png)';

        $html = (string) FastleoMessage::make($payload)->body($payload.' Заявка [срочно] a\\b')->toMail()->render();

        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->not->toContain('src="https://evil.example')
            ->and($html)->toContain('Заявка [срочно] a\\b');
    });

    it('does not turn an autolink in the text of a visitor into a link of the letter', function (): void {
        $html = (string) FastleoMessage::make('Заявка <https://evil.example/title>')->body('Перейдите <https://evil.example/pay> и <b>так</b>')->toMail()->render();

        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->toContain('https://evil.example/pay');
    });

    it('makes a letter from the title, the text and the link', function (): void {
        config(['app.url' => 'https://site.test']);

        $mail = FastleoMessage::make('Сбой импорта')->body('Строка 5')->url('/fastleo/articles')->level('danger')->toMail();

        expect($mail->subject)->toBe('Сбой импорта')
            ->and($mail->level)->toBe('error')
            ->and($mail->greeting)->toBe('Сбой импорта')
            ->and($mail->introLines)->toBe(['Строка 5'])
            ->and($mail->actionText)->toBe('Открыть')
            ->and($mail->actionUrl)->toBe('https://site.test/fastleo/articles');

        $plain = FastleoMessage::make('Готово')->level('success')->url('https://other.test/x')->toMail();

        expect($plain->level)->toBe('success')
            ->and($plain->introLines)->toBe([])
            ->and($plain->actionUrl)->toBe('https://other.test/x')
            ->and(FastleoMessage::make('Просто')->toMail()->actionText)->toBeNull()
            ->and(FastleoMessage::make('Просто')->toMail()->level)->toBe('info');
    });
});

describe('notifyAdmins()', function (): void {
    it('puts the message into the bell of every admin and nobody else', function (): void {
        $boris = admin();
        $customer = User::factory()->create();

        Fastleo::notifyAdmins(FastleoMessage::make('Новая заявка')->body('Иван')->url('/fastleo/leads/7')->icon('inbox')->level('success'));

        expect(AdminNotification::query()->pluck('user_id')->sort()->values()->all())->toBe([(string) $this->me->id, (string) $boris->id])
            ->and(AdminNotification::query()->where('user_id', (string) $customer->id)->exists())->toBeFalse();

        $entry = AdminNotification::query()->where('user_id', (string) $this->me->id)->sole();
        expect($entry->data)->toBeStoredJson(['title' => 'Новая заявка', 'body' => 'Иван', 'url' => '/fastleo/leads/7', 'icon' => 'inbox', 'level' => 'success'])
            ->and($entry->read_at)->toBeNull();
    });

    it('accepts a plain string as the title', function (): void {
        Fastleo::notifyAdmins('Привет');

        expect(AdminNotification::query()->sole()->data['title'])->toBe('Привет');
    });

    it('skips a user who lost the access in the meantime', function (): void {
        $boris = admin();
        $boris->forceFill(['fastleo_admin' => false])->save();

        Fastleo::notifyAdmins('Привет');

        expect(AdminNotification::query()->pluck('user_id')->all())->toBe([(string) $this->me->id]);
    });

    it('lets the site choose the admins', function (): void {
        $editor = User::factory()->create();
        Fastleo::resolveAdminsUsing(fn (): array => [$editor]);

        Fastleo::notifyAdmins('Редактору');

        expect(AdminNotification::query()->pluck('user_id')->all())->toBe([(string) $editor->id]);
    });

    it('notifies one admin only', function (): void {
        $boris = admin();

        Fastleo::notify($boris, 'Только тебе');

        expect(AdminNotification::query()->pluck('user_id')->all())->toBe([(string) $boris->id]);
    });

    it('goes through the notification channel so that it can be faked', function (): void {
        NotificationFacade::fake();

        Fastleo::notifyAdmins('Привет');

        NotificationFacade::assertSentTo($this->me, MessageNotification::class, fn (MessageNotification $notification): bool => $notification->via($this->me) === ['fastleo']);
    });

    it('sends nothing to messengers when there are no enabled bots', function (): void {
        Bus::fake();
        notificationBot(['enabled' => false]);

        Fastleo::notifyAdmins('Привет');

        Bus::assertNotDispatched(SendAdminMessage::class);
    });

    it('does not fail the page of the site when the bots cannot be read', function (): void {
        Bus::fake();
        notificationBot(['recipients' => ['12345']]);
        DB::beforeExecuting(function (string $query): void {
            if (str_contains($query, 'fastleo_notification_bots')) {
                throw new RuntimeException('no such table');
            }
        });

        Fastleo::notifyAdmins('Привет');

        Bus::assertNotDispatched(SendAdminMessage::class);
    });

    it('sends to messengers after the response on a web request with the sync queue, so the page does not wait for them', function (): void {
        Bus::fake();
        config(['queue.default' => 'sync']);
        notificationBot(['recipients' => ['12345']]);
        $console = new ReflectionProperty(Application::class, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            Fastleo::notifyAdmins('Привет');
        } finally {
            $console->setValue($this->app, true);
        }

        Bus::assertDispatchedAfterResponse(SendAdminMessage::class, 1);
    });

    it('sends the text in the background to every chat of every enabled bot, one job per chat', function (): void {
        Bus::fake();
        notificationBot(['recipients' => ['12345', '-100200300']]);
        notificationBot(['messenger' => 'max', 'token' => str_repeat('m', 40), 'recipients' => ['-71234567']]);

        Fastleo::notifyAdmins(FastleoMessage::make('Заявка')->body('Иван'));

        Bus::assertDispatchedTimes(SendAdminMessage::class, 3);
        Bus::assertDispatched(SendAdminMessage::class, fn (SendAdminMessage $job): bool => $job->text === "Заявка\nИван" && $job->chatId === '-71234567');
    });

    it('sends nothing to messengers when the section is switched off', function (): void {
        Bus::fake();
        notificationBot();
        config(['fastleo.notifications.bots' => null]);

        Fastleo::notifyAdmins('Привет');

        Bus::assertNotDispatched(SendAdminMessage::class);
    });
});

describe('channel', function (): void {
    it('stores a message of a custom notification and skips what it cannot store', function (): void {
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['fastleo'];
            }

            public function toFastleo(object $notifiable): FastleoMessage
            {
                return FastleoMessage::make('Своё уведомление');
            }
        };

        NotificationFacade::send([$this->me], $notification);
        expect(AdminNotification::query()->sole()->data['title'])->toBe('Своё уведомление');

        $channel = new FastleoChannel();
        $channel->send(new stdClass(), $notification);
        $channel->send($this->me, new class extends Notification {});
        $channel->send($this->me, new class extends Notification
        {
            public function toFastleo(object $notifiable): string
            {
                return 'не сообщение';
            }
        });

        expect(AdminNotification::query()->count())->toBe(1);
    });
});

describe('bell', function (): void {
    it('shows the unread count in the header', function (): void {
        Fastleo::notifyAdmins('Раз');
        Fastleo::notifyAdmins('Два');

        $this->get(route('fastleo.dashboard'))
            ->assertSee('data-bell', false)
            ->assertSee('unread: 2', false);
    });

    it('lists only own notifications, newest first, with the count of unread', function (): void {
        $boris = admin();
        AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Старое'], 'created_at' => now()->subHour(), 'read_at' => now()]);
        AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Новое', 'body' => 'Текст', 'url' => '/fastleo', 'icon' => 'inbox', 'level' => 'warning'], 'created_at' => now()]);
        AdminNotification::query()->create(['user_id' => (string) $boris->id, 'data' => ['title' => 'Чужое'], 'created_at' => now()]);

        $json = $this->getJson(route('fastleo.notifications.feed'))->assertOk()->json();

        expect($json['unread'])->toBe(1)
            ->and(array_column($json['items'], 'title'))->toBe(['Новое', 'Старое'])
            ->and($json['items'][0])->toMatchArray(['body' => 'Текст', 'url' => '/fastleo', 'icon' => 'inbox', 'level' => 'warning', 'read' => false])
            ->and($json['items'][1]['read'])->toBeTrue();
    });

    it('answers only the count when asked for it', function (): void {
        Fastleo::notifyAdmins('Раз');

        $this->getJson(route('fastleo.notifications.feed', ['count' => 1]))->assertExactJson(['unread' => 1]);
    });

    it('returns at most twenty notifications', function (): void {
        foreach (range(1, 25) as $number) {
            AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'N'.$number], 'created_at' => now()->addSeconds($number)]);
        }

        $json = $this->getJson(route('fastleo.notifications.feed'))->json();

        expect($json['items'])->toHaveCount(20)->and($json['unread'])->toBe(25);
    });

    it('never gives out a dangerous link', function (string $url): void {
        AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Плохое', 'url' => $url], 'created_at' => now()]);

        expect($this->getJson(route('fastleo.notifications.feed'))->json('items.0.url'))->toBeNull();
    })->with(['javascript' => 'javascript:alert(1)', 'protocol-relative' => '//evil.example', 'backslash' => '/\\evil.example', 'tab hides the second slash' => "/\t/evil.example"]);

    it('marks one own notification as read', function (): void {
        Fastleo::notifyAdmins('Раз');
        $entry = AdminNotification::query()->sole();

        $this->postJson(route('fastleo.notifications.read', $entry->id))->assertOk();

        expect($entry->refresh()->read_at)->not->toBeNull();
        $this->postJson(route('fastleo.notifications.read', $entry->id))->assertOk();
    });

    it('does not let an admin read or touch notifications of another admin', function (): void {
        $boris = admin();
        $foreign = AdminNotification::query()->create(['user_id' => (string) $boris->id, 'data' => ['title' => 'Чужое'], 'created_at' => now()]);

        $this->postJson(route('fastleo.notifications.read', $foreign->id))->assertNotFound();
        $this->postJson(route('fastleo.notifications.read-all'))->assertOk();

        expect($foreign->refresh()->read_at)->toBeNull();
    });

    it('marks all own notifications as read', function (): void {
        Fastleo::notifyAdmins('Раз');
        Fastleo::notifyAdmins('Два');

        $this->postJson(route('fastleo.notifications.read-all'))->assertOk();

        expect(AdminNotification::query()->whereNull('read_at')->count())->toBe(0);
        $this->getJson(route('fastleo.notifications.feed', ['count' => 1]))->assertExactJson(['unread' => 0]);
    });

    it('refuses a malformed notification number', function (): void {
        $this->postJson('/fastleo/notifications/not-a-uuid/read')->assertNotFound();
    });

    it('is closed for guests and needs a csrf token', function (): void {
        Fastleo::notifyAdmins('Раз');
        $entry = AdminNotification::query()->sole();

        $this->app['env'] = 'production';
        $this->postJson(route('fastleo.notifications.read', $entry->id))->assertStatus(419);
        $this->postJson(route('fastleo.notifications.read-all'))->assertStatus(419);

        $this->app['env'] = 'testing';
        $this->app['auth']->guard(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->getJson(route('fastleo.notifications.feed'))->assertUnauthorized();
        expect($entry->refresh()->read_at)->toBeNull();
    });
});

describe('section', function (): void {
    it('is in the menu and opens from the bell', function (): void {
        $this->get(route('fastleo.dashboard'))
            ->assertSee(route('fastleo.notifications.index'), false)
            ->assertSee('data-bell-all', false);
    });

    it('lists all own notifications, read ones too, newest first, page by page', function (): void {
        foreach (range(1, 35) as $number) {
            AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'N'.$number], 'created_at' => now()->addSeconds($number), 'read_at' => $number % 2 === 0 ? now() : null]);
        }

        AdminNotification::query()->create(['user_id' => (string) admin()->id, 'data' => ['title' => 'Чужое'], 'created_at' => now()->addHour()]);

        $this->get(route('fastleo.notifications.index'))->assertOk()
            ->assertSeeInOrder(['N35', 'N34', 'N6'])
            ->assertDontSee('Чужое')
            ->assertDontSee('>N5<', false)
            ->assertSee('data-notifications-older', false)
            ->assertSee('Непрочитанные (18)');

        $this->get(route('fastleo.notifications.index', ['page' => 2]))->assertOk()->assertSeeInOrder(['N5', 'N1'])->assertSee('data-notifications-newer', false)
            ->assertDontSee('>N6<', false)->assertDontSee('>N35<', false);
    });

    it('keeps channels, publications and notification bots together in the Messengers group', function (): void {
        publishingChannel();

        $messengers = collect(Menu::groups())->firstWhere('label', 'Мессенджеры');

        expect(array_column($messengers['items'], 'label'))->toBe(['Публикации', 'Каналы', 'Боты уведомлений']);
    });

    it('stands before the error log in the System group of the menu', function (): void {
        $system = collect(Menu::groups())->firstWhere('label', 'Система');
        $labels = array_column($system['items'], 'label');

        expect(array_search('Уведомления', $labels, true))->toBeLessThan(array_search('Журнал ошибок', $labels, true));
    });

    it('returns to the same filter and page after marking read, and to the first page once that one is empty', function (): void {
        foreach (range(1, 31) as $number) {
            AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'N'.$number], 'created_at' => now()->addSeconds($number)]);
        }

        $oldest = AdminNotification::query()->where('data->title', 'N1')->sole();

        $this->get(route('fastleo.notifications.index', ['unread' => 1, 'page' => 2]))
            ->assertSee('name="unread" value="1"', false)->assertSee('name="page" value="2"', false);

        $this->post(route('fastleo.notifications.read', $oldest->id), ['unread' => '1', 'page' => '2'])
            ->assertRedirect(route('fastleo.notifications.index', ['unread' => 1, 'page' => 2]));
        $this->get(route('fastleo.notifications.index', ['unread' => 1, 'page' => 2]))->assertRedirect(route('fastleo.notifications.index', ['unread' => 1]));

        $this->post(route('fastleo.notifications.read', $oldest->id), ['page' => str_repeat('9', 23)])->assertRedirect(route('fastleo.notifications.index'));
        $this->post(route('fastleo.notifications.read-all'), ['unread' => '1', 'page' => '2'])->assertRedirect(route('fastleo.notifications.index', ['unread' => 1]));
    });

    it('does not let the form touch a notification of another admin', function (): void {
        $foreign = AdminNotification::query()->create(['user_id' => (string) admin()->id, 'data' => ['title' => 'Чужое', 'url' => '/fastleo'], 'created_at' => now()]);

        $this->post(route('fastleo.notifications.read', $foreign->id), ['open' => '1'])->assertNotFound();

        expect($foreign->fresh()?->read_at)->toBeNull();
    });

    it('shows a notification with broken data instead of failing the page', function (): void {
        AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => ['не строка'], 'level' => 5], 'created_at' => now()]);

        $this->get(route('fastleo.notifications.index'))->assertOk()->assertSee('data-notification="unread"', false);
    });

    it('shows only unread notifications when asked', function (): void {
        AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Прочитанное'], 'created_at' => now(), 'read_at' => now()]);
        AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Новое'], 'created_at' => now()]);

        $this->get(route('fastleo.notifications.index', ['unread' => 1]))->assertOk()->assertSee('Новое')->assertDontSee('Прочитанное');

        AdminNotification::query()->update(['read_at' => now()]);

        $this->get(route('fastleo.notifications.index', ['unread' => 1]))->assertSee('Новых уведомлений нет.')->assertDontSee('data-notifications-read-all', false);
        $this->get(route('fastleo.notifications.index'))->assertSee('Прочитанное');
    });

    it('marks a notification read and opens its link, or goes back without one', function (): void {
        $withLink = AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Заявка', 'url' => '/fastleo/leads/7'], 'created_at' => now()]);
        $withoutLink = AdminNotification::query()->create(['user_id' => (string) $this->me->id, 'data' => ['title' => 'Без ссылки', 'url' => 'javascript:alert(1)'], 'created_at' => now()]);
        $back = route('fastleo.notifications.index');

        $this->from($back)->post(route('fastleo.notifications.read', $withLink->id), ['open' => '1'])->assertRedirect('/fastleo/leads/7');
        $this->from($back)->post(route('fastleo.notifications.read', $withoutLink->id), ['open' => '1'])->assertRedirect($back);

        expect(AdminNotification::query()->whereNull('read_at')->count())->toBe(0);
        $this->get($back)->assertSee('/fastleo/leads/7')->assertDontSee('javascript:', false);
    });

    it('marks everything read from the page and says so', function (): void {
        Fastleo::notifyAdmins('Раз');

        $this->post(route('fastleo.notifications.read-all'))->assertRedirect(route('fastleo.notifications.index'))->assertSessionHas('fastleo.success', 'Все уведомления отмечены прочитанными.');

        expect(AdminNotification::query()->whereNull('read_at')->exists())->toBeFalse();
    });

    it('is not in the menu and answers 404 while the bell is off', function (): void {
        config(['fastleo.notifications.bell' => false]);

        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.notifications.index').'"', false);
        $this->get(route('fastleo.notifications.index'))->assertNotFound();
    });
});

describe('switched off bell', function (): void {
    beforeEach(function (): void {
        config(['fastleo.notifications.bell' => false]);
    });

    it('has no bell in the header and answers 404 at its addresses', function (): void {
        $this->get(route('fastleo.dashboard'))->assertOk()->assertDontSee('data-bell', false);

        $this->getJson(route('fastleo.notifications.feed'))->assertNotFound();
        $this->postJson(route('fastleo.notifications.read-all'))->assertNotFound();
        $this->postJson(route('fastleo.notifications.read', ['id' => '00000000-0000-0000-0000-000000000000']))->assertNotFound();
    });

    it('does not even run the bell channel', function (): void {
        NotificationFacade::fake();

        Fastleo::notifyAdmins('Новая заявка');
        Fastleo::notify($this->me, 'Импорт завершён');

        NotificationFacade::assertNothingSent();
    });

    it('stores nothing from a notification class of the site with the fastleo channel', function (): void {
        NotificationFacade::send([$this->me], new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['fastleo'];
            }

            public function toFastleo(object $notifiable): FastleoMessage
            {
                return FastleoMessage::make('Своё уведомление');
            }
        });

        expect(AdminNotification::query()->count())->toBe(0);
    });

    it('stores nothing, but still mails and sends to the bots', function (): void {
        config(['fastleo.notifications.mail' => true, 'mail.default' => 'array']);
        Bus::fake([SendAdminMessage::class]);
        notificationBot(['recipients' => ['12345']]);

        Fastleo::notifyAdmins('Новая заявка');
        Fastleo::notify($this->me, 'Импорт завершён');

        expect(AdminNotification::query()->count())->toBe(0)
            ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
        Bus::assertDispatchedTimes(SendAdminMessage::class, 1);
    });
});

it('reads the switch leniently, so a value from env() does not break every page', function (mixed $value, bool $enabled): void {
    config(['fastleo.notifications.bell' => $value]);

    expect(Fastleo::bell())->toBe($enabled);
    $this->get(route('fastleo.dashboard'))->assertOk();
})->with([
    'null from a missing env variable' => [null, false],
    'one' => [1, true],
    'string true' => ['true', true],
    'string false' => ['false', false],
]);

describe('polling', function (): void {
    it('refreshes the count as often as the site asks, but not more often than every ten seconds', function (mixed $seconds, int $milliseconds): void {
        config(['fastleo.notifications.poll' => $seconds]);

        $this->get(route('fastleo.dashboard'))->assertSee('poll: '.$milliseconds.' })', false);
    })->with([
        'two minutes' => [120, 120000],
        'too often' => [5, 10000],
        'a month, longer than the browser timer holds' => [2592000, 86400000],
        'a number too big for an integer' => ['1e30', 86400000],
        'never' => [null, 0],
        'zero' => [0, 0],
    ]);
});

describe('via', function (): void {
    beforeEach(function (): void {
        config(['fastleo.notifications.mail' => true, 'mail.default' => 'array']);
        Bus::fake([SendAdminMessage::class]);
        notificationBot(['recipients' => ['12345']]);
    });

    it('sends only to the chosen channels', function (): void {
        Fastleo::notifyAdmins('Сайт недоступен', via: ['bots']);

        expect(AdminNotification::query()->count())->toBe(0)
            ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
        Bus::assertDispatchedTimes(SendAdminMessage::class, 1);

        Fastleo::notifyAdmins('Новая заявка', via: ['bell', 'mail']);

        expect(AdminNotification::query()->count())->toBe(1)
            ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
        Bus::assertDispatchedTimes(SendAdminMessage::class, 1);
    });

    it('reads the mail switch leniently, so a value from env() does not break the form of the site', function (): void {
        config(['fastleo.notifications.mail' => null]);
        Fastleo::notifyAdmins('Новая заявка');

        config(['fastleo.notifications.mail' => 'true']);
        Fastleo::notifyAdmins('Ещё заявка');

        expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
    });

    it('skips a chosen channel that the site switched off', function (): void {
        config(['fastleo.notifications.mail' => false]);

        Fastleo::notifyAdmins('Новая заявка', via: ['mail', 'bell']);

        expect(AdminNotification::query()->count())->toBe(1)
            ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('fails loudly on an unknown channel instead of losing the message', function (): void {
        expect(fn () => Fastleo::notifyAdmins('Новая заявка', via: ['bell', 'telegram']))
            ->toThrow(InvalidArgumentException::class, 'неизвестный канал «telegram», допустимы: bell, mail, bots');

        expect(AdminNotification::query()->count())->toBe(0);
    });

    it('names a channel that is not a string and refuses an empty list', function (): void {
        expect(fn () => Fastleo::notifyAdmins('Новая заявка', via: ['bell', 0]))
            ->toThrow(InvalidArgumentException::class, 'неизвестный канал «int», допустимы: bell, mail, bots')
            ->and(fn () => Fastleo::notifyAdmins('Новая заявка', via: []))
            ->toThrow(InvalidArgumentException::class, 'пустой список каналов via, допустимы: bell, mail, bots; во все включённые — без via');
    });
});

describe('pruning', function (): void {
    it('deletes notifications older than the keep period', function (): void {
        AdminNotification::query()->create(['user_id' => '1', 'data' => ['title' => 'Старое'], 'created_at' => now()->subDays(100)]);
        AdminNotification::query()->create(['user_id' => '1', 'data' => ['title' => 'Свежее'], 'created_at' => now()->subDays(5)]);

        $this->artisan('fastleo:prune')->assertSuccessful();

        expect(AdminNotification::query()->count())->toBe(1);

        config(['fastleo.notifications.keep_days' => 2]);
        $this->artisan('fastleo:prune')->assertSuccessful();
        expect(AdminNotification::query()->count())->toBe(0);
    });
});
