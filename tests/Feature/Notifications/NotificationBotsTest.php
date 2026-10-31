<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\DescribeResource;
use Fastleo\Mcp\Tools\ListResources;
use Fastleo\Messengers\MessengerFailed;
use Fastleo\Notifications\NotificationBot;
use Fastleo\Notifications\SendAdminMessage;
use Fastleo\Notifications\SendTestMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->me = admin();
    $this->actingAs($this->me, Fastleo::GUARD);
    Http::preventStrayRequests();
});

/**
 * Данные формы бота уведомлений.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function botForm(array $overrides = []): array
{
    return ['name' => 'Админы', 'messenger' => 'telegram', 'token' => TELEGRAM_TOKEN, 'recipients' => ['12345', '-100200300'], 'enabled' => '1', ...$overrides];
}

describe('section', function (): void {
    it('is in the menu under its own address', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.resources.index', 'notification-bots'), false);
        $this->get(route('fastleo.resources.create', 'notification-bots'))->assertOk()->assertSee('Токен бота')->assertSee('data-find-chats', false);
    });

    it('saves a bot with the token encrypted and never prints the token again', function (): void {
        $this->post(route('fastleo.resources.store', 'notification-bots'), botForm())->assertSessionHasNoErrors();

        $bot = NotificationBot::query()->sole();
        expect($bot->token)->toBe(TELEGRAM_TOKEN)
            ->and($bot->recipients)->toBe(['12345', '-100200300'])
            ->and((string) DB::table('fastleo_notification_bots')->value('token'))->not->toContain('AAAAAAAAAAAA');

        $this->get(route('fastleo.resources.edit', ['notification-bots', $bot->id]))->assertOk()->assertDontSee('AAAAAAAAAAAA');
        $this->get(route('fastleo.resources.show', ['notification-bots', $bot->id]))->assertOk()->assertDontSee('AAAAAAAAAAAA');
        expect(Activity::query()->get()->toJson())->not->toContain('AAAAAAAAAAAA');
    });

    it('keeps the saved token when the field is left empty', function (): void {
        $bot = notificationBot();

        $this->put(route('fastleo.resources.update', ['notification-bots', $bot->id]), botForm(['token' => '', 'recipients' => ['777']]))->assertSessionHasNoErrors();

        expect($bot->refresh()->token)->toBe(TELEGRAM_TOKEN)->and($bot->recipients)->toBe(['777']);
    });

    it('checks the token and the chats against the chosen messenger', function (): void {
        $this->post(route('fastleo.resources.store', 'notification-bots'), botForm(['token' => 'не токен']))
            ->assertSessionHasErrors(['token' => 'Токен не похож на токен бота Telegram.']);

        $this->post(route('fastleo.resources.store', 'notification-bots'), botForm(['messenger' => 'max', 'token' => MAX_TOKEN, 'recipients' => ['@channel_name']]))
            ->assertSessionHasErrors(['recipients' => 'Неверный номер чата @channel_name: для Max нужен номер (в Telegram — ещё @имя канала).']);

        expect(NotificationBot::query()->count())->toBe(0);
    });

    it('does not hand the saved token to another messenger when it is switched', function (): void {
        $bot = notificationBot();
        $url = route('fastleo.resources.update', ['notification-bots', $bot->id]);

        $this->put($url, botForm(['messenger' => 'max', 'token' => '', 'recipients' => ['-71234567']]))
            ->assertSessionHasErrors(['token' => 'Вы сменили мессенджер: введите токен бота Max.']);
        expect($bot->refresh()->messenger->value)->toBe('telegram');

        $this->put($url, botForm(['messenger' => 'max', 'token' => MAX_TOKEN, 'recipients' => ['-71234567']]))->assertSessionHasNoErrors();
        expect($bot->refresh())->messenger->value->toBe('max')->token->toBe(MAX_TOKEN);
    });

    it('explains what it is under the title of the list', function (): void {
        $this->get(route('fastleo.resources.index', 'notification-bots'))->assertOk()->assertSee('Боты Telegram и Max, которые дублируют уведомления колокольчика');
    });

    it('checks the token and the chats only after the messenger is chosen', function (): void {
        $this->post(route('fastleo.resources.store', 'notification-bots'), botForm(['messenger' => '', 'token' => 'не токен', 'recipients' => ['@channel_name']]))
            ->assertSessionHasErrors('messenger')
            ->assertSessionDoesntHaveErrors(['token', 'recipients']);
    });

    it('can be switched off in the config', function (): void {
        config(['fastleo.notifications.bots' => null]);
        Fastleo::resources()->flush();

        $this->get(route('fastleo.resources.index', 'notification-bots'))->assertNotFound();
    });

    it('is not available to AI agents through MCP', function (): void {
        FastleoServer::actingAs($this->me, 'fastleo')->tool(ListResources::class)->assertDontSee('notification-bots');
        FastleoServer::actingAs($this->me, 'fastleo')->tool(DescribeResource::class, ['resource' => 'notification-bots'])->assertHasErrors(['Раздела «notification-bots» нет']);
    });
});

describe('test message', function (): void {
    it('is sent at once to every chat of the bot', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        $bot = notificationBot(['recipients' => ['12345', '-100200300']]);

        $this->post(route('fastleo.resources.action', ['notification-bots', 'send-test']), ['ids' => [(string) $bot->id]])
            ->assertSessionHas('fastleo.success', 'Тестовое сообщение отправлено.');

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request): bool => $request['chat_id'] === '-100200300' && str_contains($request['text'], 'Проверка связи'));
    });

    it('names the chats that failed without showing the token', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked '.TELEGRAM_TOKEN], 403)]);
        $bot = notificationBot();

        $this->post(route('fastleo.resources.action', ['notification-bots', 'send-test']), ['ids' => [(string) $bot->id]])
            ->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, '12345: Forbidden: bot was blocked') && ! str_contains($message, TELEGRAM_TOKEN));
    });
});

describe('test message without a bot', function (): void {
    it('answers with an error when the action got no bot', function (): void {
        app()->setLocale(Fastleo::LOCALE);

        $result = new SendTestMessage()->handle(new EloquentCollection, []);

        expect($result->type)->toBe('error')->and($result->message)->toBe('Сообщение не отправлено: —');
    });
});

describe('job', function (): void {
    it('sends the text to its chat', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);

        dispatch_sync(new SendAdminMessage(notificationBot(), '12345', 'Заявка'));

        Http::assertSent(fn (HttpRequest $request): bool => $request['text'] === 'Заявка' && $request['chat_id'] === '12345');
    });

    it('does nothing when the bot was switched off meanwhile', function (): void {
        Http::fake();

        dispatch_sync(new SendAdminMessage(notificationBot(['enabled' => false]), '12345', 'Заявка'));

        Http::assertNothingSent();
    });

    it('reports the failure instead of breaking the page when the queue is sync', function (): void {
        Exceptions::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        dispatch_sync(new SendAdminMessage(notificationBot(), '12345', 'Заявка'));

        Exceptions::assertReported(fn (MessengerFailed $exception): bool => $exception->getMessage() === 'chat not found');
    });

    it('fails with the reason of the messenger', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        expect(fn () => new SendAdminMessage(notificationBot(), '12345', 'Заявка')->handle())->toThrow(MessengerFailed::class, 'chat not found');
    });

    it('puts itself back in the queue for the time the messenger asks to wait', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 7]], 429)]);
        $job = new SendAdminMessage(notificationBot(), '12345', 'Заявка')->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(7);
        $job->assertNotFailed();
    });

    it('does not repeat a message that may have already arrived', function (): void {
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $job = new SendAdminMessage(notificationBot(), '12345', 'Заявка')->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailed();
        $job->assertNotReleased();
    });
});
