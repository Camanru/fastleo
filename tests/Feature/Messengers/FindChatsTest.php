<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Notifications\NotificationBot;
use Fastleo\Tests\Fixtures\Policies\ReadOnlyBotPolicy;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    Http::preventStrayRequests();
});

/**
 * Telegram отдаёт одно событие: пост в канале «Новости».
 */
function fakeTelegramChannel(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
        ['channel_post' => ['chat' => ['id' => -100200300, 'type' => 'channel', 'title' => 'Новости']]],
    ]])]);
}

/**
 * Запрос «Найти чаты бота» поля получателей раздела «Уведомления».
 *
 * @param  array<string, mixed>  $data
 */
function findChats(array $data): TestResponse
{
    return test()->postJson(route('fastleo.resources.chats', ['notification-bots', 'recipients']), $data);
}

it('lists the chats of the bot with the token typed into the form', function (): void {
    fakeTelegramChannel();

    findChats(['messenger' => 'telegram', 'token' => TELEGRAM_TOKEN])
        ->assertOk()
        ->assertExactJson(['chats' => [['id' => '-100200300', 'title' => 'Новости', 'type' => 'канал']]]);

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/getUpdates'));
});

it('uses the saved token of the bot when the field is empty', function (): void {
    fakeTelegramChannel();
    $bot = notificationBot();

    findChats(['messenger' => 'telegram', 'token' => '', 'record' => (string) $bot->id])->assertOk()->assertJsonPath('chats.0.id', '-100200300');

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), TELEGRAM_TOKEN));
});

it('does not use the saved token without the right to change the bot', function (): void {
    Http::fake();
    Gate::policy(NotificationBot::class, ReadOnlyBotPolicy::class);
    $bot = notificationBot();

    findChats(['messenger' => 'telegram', 'record' => (string) $bot->id])->assertForbidden();
    Http::assertNothingSent();
});

it('explains what is missing and sends nothing', function (array $data, string $error): void {
    Http::fake();

    findChats($data)->assertUnprocessable()->assertExactJson(['error' => $error]);

    Http::assertNothingSent();
})->with([
    'no messenger' => [['token' => TELEGRAM_TOKEN], 'Сначала выберите мессенджер.'],
    'no token' => [['messenger' => 'telegram'], 'Сначала укажите токен бота.'],
    'token of another messenger' => [['messenger' => 'telegram', 'token' => MAX_TOKEN], 'Токен не похож на токен бота Telegram.'],
]);

it('shows the reason when the messenger refuses', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => "Conflict: can't use getUpdates method while webhook is active"], 409)]);

    findChats(['messenger' => 'telegram', 'token' => TELEGRAM_TOKEN])
        ->assertUnprocessable()
        ->assertJsonPath('error', "Не удалось получить чаты: Conflict: can't use getUpdates method while webhook is active");
});

it('works only for a chat field of a section the admin may open', function (): void {
    $this->postJson(route('fastleo.resources.chats', ['notification-bots', 'name']), ['messenger' => 'telegram', 'token' => TELEGRAM_TOKEN])->assertNotFound();
    $this->postJson(route('fastleo.resources.chats', ['articles', 'recipients']), ['messenger' => 'telegram', 'token' => TELEGRAM_TOKEN])->assertNotFound();
});

it('needs a csrf token and a signed-in admin', function (): void {
    $this->app['env'] = 'production';
    $this->post(route('fastleo.resources.chats', ['notification-bots', 'recipients']))->assertStatus(419);

    $this->app['env'] = 'testing';
    $this->app['auth']->guard(Fastleo::GUARD)->logout();
    $this->flushSession();
    $this->post(route('fastleo.resources.chats', ['notification-bots', 'recipients']))->assertRedirect(route('fastleo.login'));
});
