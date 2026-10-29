<?php

declare(strict_types=1);

use Fastleo\Messengers\MaxBot;
use Fastleo\Messengers\Messenger;
use Fastleo\Messengers\MessengerFailed;
use Fastleo\Messengers\TelegramBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Поймать ошибку мессенджера.
 */
function messengerFailure(callable $call): MessengerFailed
{
    try {
        $call();
    } catch (MessengerFailed $exception) {
        return $exception;
    }

    throw new RuntimeException('Ожидалась ошибка мессенджера');
}

describe('telegram', function (): void {
    it('sends plain text without a link preview and returns the message id', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);

        expect(new TelegramBot(TELEGRAM_TOKEN)->sendText('@my_channel', 'Привет'))->toBe('42');

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'
            && $request['chat_id'] === '@my_channel'
            && $request['text'] === 'Привет'
            && $request['link_preview_options'] === ['is_disabled' => true]
            && ! isset($request['parse_mode']));
    });

    it('cuts a very long text to the limit', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        new TelegramBot(TELEGRAM_TOKEN)->sendText('1', str_repeat('а', 5000));

        Http::assertSent(fn (HttpRequest $request): bool => mb_strlen($request['text']) === 4000 && str_ends_with($request['text'], '…'));
    });

    it('explains a rejection without the token and passes the waiting time', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests '.TELEGRAM_TOKEN, 'parameters' => ['retry_after' => 7]], 429)]);

        $failure = messengerFailure(fn (): string => new TelegramBot(TELEGRAM_TOKEN)->sendText('1', 'Привет'));

        expect($failure->getMessage())->toBe('Too Many Requests ***')
            ->and($failure->retryAfter)->toBe(7)
            ->and($failure->uncertain)->toBeFalse();
    });

    it('falls back to the status code when there is no description', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response('Bad gateway', 502)]);

        expect(messengerFailure(fn (): array => new TelegramBot(TELEGRAM_TOKEN)->me())->getMessage())->toBe('HTTP 502');
    });

    it('does not leak the token when the connection breaks and says whether the message could have arrived', function (string $error, bool $uncertain): void {
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException($error.' for https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage')]);

        $failure = messengerFailure(fn (): string => new TelegramBot(TELEGRAM_TOKEN)->sendText('1', 'Привет'));

        expect($failure->getMessage())->toBe('нет связи с Telegram')
            ->and($failure->getPrevious())->toBeNull()
            ->and($failure->uncertain)->toBe($uncertain);
    })->with([
        'timeout after sending' => ['cURL error 28: Operation timed out', true],
        'unknown host' => ['cURL error 6: Could not resolve host', false],
        'refused connection' => ['cURL error 7: Failed to connect', false],
    ]);

    it('names the bot by its username', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => 777, 'first_name' => 'Сайт', 'username' => 'site_bot']])]);

        expect(new TelegramBot(TELEGRAM_TOKEN)->me())->toBe(['id' => '777', 'name' => '@site_bot']);
    });

    it('finds the chats from the recent updates of the bot', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
            ['message' => ['chat' => ['id' => 12345, 'type' => 'private', 'first_name' => 'Анна', 'last_name' => 'Иванова']]],
            ['channel_post' => ['chat' => ['id' => -100200300, 'type' => 'channel', 'title' => 'Новости']]],
            ['my_chat_member' => ['chat' => ['id' => -100200300, 'type' => 'channel', 'title' => 'Новости']]],
            ['message' => ['chat' => ['id' => 55, 'type' => 'private', 'username' => 'boris']]],
            ['edited_message' => []],
        ]])]);

        expect(new TelegramBot(TELEGRAM_TOKEN)->recentChats())->toBe([
            ['id' => '12345', 'title' => 'Анна Иванова', 'type' => 'private'],
            ['id' => '-100200300', 'title' => 'Новости', 'type' => 'channel'],
            ['id' => '55', 'title' => '@boris', 'type' => 'private'],
        ]);
    });
});

describe('max', function (): void {
    it('sends text to the chat with the token in the header and returns the message id', function (): void {
        Http::fake(['platform-api2.max.ru/*' => Http::response(['message' => ['body' => ['mid' => 'mid.abc']]])]);

        expect(new MaxBot(MAX_TOKEN)->sendText('-71234567', 'Привет'))->toBe('mid.abc');

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://platform-api2.max.ru/messages?chat_id=-71234567'
            && $request->header('Authorization') === [MAX_TOKEN]
            && $request['text'] === 'Привет'
            && ! str_contains($request->url(), MAX_TOKEN));
    });

    it('treats an answer without the message id as a failure', function (): void {
        Http::fake(['platform-api2.max.ru/*' => Http::response(['success' => false])]);

        expect(messengerFailure(fn (): string => new MaxBot(MAX_TOKEN)->sendText('1', 'Привет'))->getMessage())->toBe('Max прислал непонятный ответ');
    });

    it('explains a rejection without the token', function (): void {
        Http::fake(['platform-api2.max.ru/*' => Http::response(['code' => 'chat.denied', 'message' => 'Access denied for '.MAX_TOKEN], 403)]);

        expect(messengerFailure(fn (): string => new MaxBot(MAX_TOKEN)->sendText('1', 'Привет'))->getMessage())->toBe('Access denied for ***');
    });

    it('asks to wait after too many requests', function (): void {
        Http::fake(['platform-api2.max.ru/*' => Http::response(['code' => 'too.many.requests'], 429)]);

        $failure = messengerFailure(fn (): array => new MaxBot(MAX_TOKEN)->me());

        expect($failure->getMessage())->toBe('too.many.requests')->and($failure->retryAfter)->toBe(1);
    });

    it('uses the address from the config', function (): void {
        config(['fastleo.messengers.max.url' => 'https://max.example.com/']);
        Http::fake(['max.example.com/*' => Http::response(['user_id' => 9, 'name' => 'Сайт', 'username' => ''])]);

        expect(new MaxBot(MAX_TOKEN)->me())->toBe(['id' => '9', 'name' => 'Сайт']);
    });

    it('trusts the root certificate of the Ministry shipped with the package unless the site sets its own', function (): void {
        expect(file_get_contents(MaxBot::certificate()))->toStartWith('-----BEGIN CERTIFICATE-----');

        config(['fastleo.messengers.max.certificate' => '/etc/ssl/ministry.pem']);

        expect(MaxBot::certificate())->toBe('/etc/ssl/ministry.pem');
    });

    it('finds the chats where the bot was added or written to, with titles', function (): void {
        Http::fake([
            'platform-api2.max.ru/updates*' => Http::response(['updates' => [
                ['update_type' => 'bot_added', 'chat_id' => -71234567, 'is_channel' => true],
                ['update_type' => 'message_created', 'message' => ['recipient' => ['chat_id' => 555, 'chat_type' => 'dialog'], 'sender' => ['name' => 'Анна']]],
                ['update_type' => 'bot_added', 'chat_id' => -800, 'is_channel' => false],
            ]]),
            'platform-api2.max.ru/chats/-71234567' => Http::response(['title' => 'Новости']),
            'platform-api2.max.ru/chats/-800' => Http::response(['code' => 'not.found'], 404),
        ]);

        expect(new MaxBot(MAX_TOKEN)->recentChats())->toBe([
            ['id' => '-71234567', 'title' => 'Новости', 'type' => 'channel'],
            ['id' => '555', 'title' => 'Анна', 'type' => 'dialog'],
            ['id' => '-800', 'title' => '-800', 'type' => 'chat'],
        ]);
    });
});

it('creates the client of the chosen messenger', function (): void {
    expect(Messenger::Telegram->bot(TELEGRAM_TOKEN))->toBeInstanceOf(TelegramBot::class)
        ->and(Messenger::Max->bot(MAX_TOKEN))->toBeInstanceOf(MaxBot::class)
        ->and(Messenger::Max->bot(MAX_TOKEN)->messenger())->toBe(Messenger::Max);
});
