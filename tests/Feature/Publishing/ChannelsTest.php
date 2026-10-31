<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\ListResources;
use Fastleo\Publishing\Channel;
use Fastleo\Publishing\Publication;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->me = admin();
    $this->actingAs($this->me, Fastleo::GUARD);
    Http::preventStrayRequests();
});

/**
 * Данные формы канала.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function channelForm(array $overrides = []): array
{
    return ['name' => '', 'messenger' => 'telegram', 'token' => TELEGRAM_TOKEN, 'chat_id' => ['@news_channel'], 'enabled' => '1', ...$overrides];
}

/**
 * Ответы Telegram на проверку канала: бот — администратор с правами `$rights`.
 *
 * @param  array<string, bool>  $rights
 */
function fakeTelegramChannelCheck(array $rights = [], string $status = 'administrator', string $type = 'channel'): void
{
    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777, 'username' => 'site_bot']]),
        'api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001234567890, 'type' => $type, 'title' => 'Новости клиники']]),
        'api.telegram.org/*/getChatMember' => Http::response(['ok' => true, 'result' => [
            'status' => $status,
            ...['can_post_messages' => true, 'can_edit_messages' => true, 'can_delete_messages' => true, ...$rights],
        ]]),
    ]);
}

it('checks the bot in the channel and saves the permanent number and the title', function (): void {
    fakeTelegramChannelCheck();

    $this->post(route('fastleo.resources.store', 'channels'), channelForm())->assertSessionHasNoErrors();

    $channel = Channel::query()->sole();
    expect($channel->chat_id)->toBe('-1001234567890')
        ->and($channel->name)->toBe('Новости клиники')
        ->and($channel->token)->toBe(TELEGRAM_TOKEN)
        ->and((string) DB::table('fastleo_channels')->value('token'))->not->toContain('AAAAAAAAAAAA');

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/getChatMember') && $request['user_id'] === 777 && $request['chat_id'] === '-1001234567890');
    $this->get(route('fastleo.resources.edit', ['channels', $channel->id]))->assertOk()->assertDontSee('AAAAAAAAAAAA');
});

it('keeps its own name', function (): void {
    fakeTelegramChannelCheck();

    $this->post(route('fastleo.resources.store', 'channels'), channelForm(['name' => 'Мой канал']))->assertSessionHasNoErrors();

    expect(Channel::query()->sole()->name)->toBe('Мой канал');
});

it('refuses a channel where the bot cannot publish, edit or delete', function (array $rights, string $status, string $message): void {
    fakeTelegramChannelCheck($rights, $status);

    $this->post(route('fastleo.resources.store', 'channels'), channelForm())->assertSessionHasErrors(['chat_id' => $message]);

    expect(Channel::query()->count())->toBe(0);
})->with([
    'not an admin' => [[], 'member', 'Канал не прошёл проверку: Бот не администратор этого канала: добавьте его в администраторы канала.'],
    'no rights to edit and delete' => [['can_edit_messages' => false, 'can_delete_messages' => false], 'administrator', 'Канал не прошёл проверку: У бота в канале нет прав: редактировать, удалять. Включите их в настройках администратора канала.'],
]);

it('accepts a group where the bot is a member', function (): void {
    fakeTelegramChannelCheck([], 'member', 'supergroup');

    $this->post(route('fastleo.resources.store', 'channels'), channelForm())->assertSessionHasNoErrors();
});

it('shows the reason of the messenger without the token', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized '.TELEGRAM_TOKEN], 401)]);

    $this->post(route('fastleo.resources.store', 'channels'), channelForm())
        ->assertSessionHasErrors(['chat_id' => 'Канал не прошёл проверку: Unauthorized ***']);
});

it('does not ask the messenger when nothing important changed or the channel is switched off', function (): void {
    Http::fake();
    $channel = publishingChannel();

    $this->put(route('fastleo.resources.update', ['channels', $channel->id]), channelForm(['name' => 'Другое', 'token' => '', 'chat_id' => ['-1001234567890']]))->assertSessionHasNoErrors();
    $this->put(route('fastleo.resources.update', ['channels', $channel->id]), channelForm(['token' => '', 'chat_id' => ['-100999'], 'enabled' => '0']))->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect($channel->refresh()->name)->toBe('Другое')->and($channel->enabled)->toBeFalse()->and($channel->token)->toBe(TELEGRAM_TOKEN);
});

it('checks again with the saved token when the channel is switched on', function (): void {
    fakeTelegramChannelCheck();
    $channel = publishingChannel(['enabled' => false]);

    $this->put(route('fastleo.resources.update', ['channels', $channel->id]), channelForm(['name' => 'Новости', 'token' => '', 'chat_id' => ['-1001234567890']]))->assertSessionHasNoErrors();

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), TELEGRAM_TOKEN.'/getChatMember'));
});

it('does not hand the saved token to another messenger when it is switched', function (): void {
    Http::fake();
    $channel = publishingChannel();

    $this->put(route('fastleo.resources.update', ['channels', $channel->id]), channelForm(['messenger' => 'max', 'token' => '', 'chat_id' => ['-71234567']]))
        ->assertSessionHasErrors(['token' => 'Вы сменили мессенджер: введите токен бота Max.']);

    Http::assertNothingSent();
    expect($channel->refresh()->messenger->value)->toBe('telegram');
});

it('keeps the messenger and the chat once posts were sent through the channel', function (): void {
    fakeTelegramChannelCheck();
    $channel = publishingChannel();
    $url = route('fastleo.resources.update', ['channels', $channel->id]);

    $this->put($url, channelForm(['name' => 'Другое', 'token' => '', 'chat_id' => ['-1009999']]))->assertSessionHasNoErrors();

    Publication::query()->create(['text' => 'Пост', 'channel_ids' => [(string) $channel->id], 'status' => 'sent'])
        ->deliveries()->create(['channel_id' => $channel->id, 'status' => 'sent']);

    $this->put($url, channelForm(['token' => '', 'chat_id' => ['-1009999']]))->assertSessionHasErrors(['chat_id' => 'Через этот канал уже отправляли посты: мессенджер и номер канала менять нельзя, иначе «Удалить из каналов» заденет чужие посты. Выключите канал и создайте новый.']);
    $this->put($url, channelForm(['token' => '', 'messenger' => 'max', 'chat_id' => ['-1001234567890']]))->assertSessionHasErrors('messenger');
    $this->put($url, channelForm(['name' => 'Новое имя', 'token' => '', 'chat_id' => ['-1001234567890']]))->assertSessionHasNoErrors();

    expect($channel->refresh())->chat_id->toBe('-1001234567890')->name->toBe('Новое имя');
});

it('checks a Max channel: active, the bot is an admin with the rights under old or new names', function (): void {
    Http::fake([
        'platform-api2.max.ru/me' => Http::response(['user_id' => 9, 'name' => 'Бот']),
        'platform-api2.max.ru/chats/-71234567' => Http::response(['chat_id' => -71234567, 'type' => 'channel', 'status' => 'active', 'title' => 'Канал в Max']),
        'platform-api2.max.ru/chats/-71234567/members/me' => Http::response(['is_admin' => true, 'permissions' => ['post_edit_delete_message', 'edit', 'delete_message']]),
    ]);

    $this->post(route('fastleo.resources.store', 'channels'), channelForm(['messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => ['-71234567']]))->assertSessionHasNoErrors();

    expect(Channel::query()->sole())->name->toBe('Канал в Max')->chat_id->toBe('-71234567');
});

it('refuses a Max channel the bot left or cannot publish to', function (array $chat, array $member, string $reason): void {
    Http::fake([
        'platform-api2.max.ru/me' => Http::response(['user_id' => 9, 'name' => 'Бот']),
        'platform-api2.max.ru/chats/-71234567' => Http::response(['chat_id' => -71234567, 'type' => 'channel', 'title' => 'Канал', ...$chat]),
        'platform-api2.max.ru/chats/-71234567/members/me' => Http::response($member),
    ]);

    $this->post(route('fastleo.resources.store', 'channels'), channelForm(['messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => ['-71234567']]))
        ->assertSessionHasErrors(['chat_id' => 'Канал не прошёл проверку: '.$reason]);
})->with([
    'left' => [['status' => 'left'], [], 'Чат недоступен боту: он удалён или бот из него вышел.'],
    'not an admin' => [['status' => 'active'], ['is_admin' => false], 'Бот не администратор этого канала: добавьте его в администраторы канала.'],
    'no right to publish' => [['status' => 'active'], ['is_admin' => true, 'permissions' => ['edit', 'delete']], 'У бота в канале нет прав: публиковать. Включите их в настройках администратора канала.'],
]);

it('rejects an @name for Max before asking it', function (): void {
    Http::fake();

    $this->post(route('fastleo.resources.store', 'channels'), channelForm(['messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => ['@news']]))->assertSessionHasErrors('chat_id');

    Http::assertNothingSent();
});

it('is closed for MCP and can be switched off in the config', function (): void {
    FastleoServer::actingAs($this->me, 'fastleo')->tool(ListResources::class)->assertDontSee('"resource":"channels"');

    config(['fastleo.publishing.channels' => null]);
    Fastleo::resources()->flush();

    $this->get(route('fastleo.resources.index', 'channels'))->assertNotFound();
});

it('knows whether there is an enabled channel', function (): void {
    expect(Channel::anyEnabled())->toBeFalse();

    $channel = publishingChannel(['enabled' => false]);
    expect(Channel::anyEnabled())->toBeFalse();

    $channel->update(['enabled' => true]);
    expect(Channel::anyEnabled())->toBeTrue();
});

it('explains what the section is for under the title of the list', function (): void {
    $this->get(route('fastleo.resources.index', 'channels'))->assertOk()->assertSee('Каналы Telegram и Max, куда публикуются записи сайта.');
});

it('has no channels while the migrations have not been run', function (): void {
    Schema::drop('fastleo_channels');

    expect(Channel::anyEnabled())->toBeFalse();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'DROP TABLE с зависимыми объектами: в PostgreSQL мешают внешние ключи, в MySQL он закрывает транзакцию теста');
