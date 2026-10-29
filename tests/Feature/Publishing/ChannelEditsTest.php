<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Publishing\Channel;
use Fastleo\Publishing\Delivery;
use Fastleo\Publishing\Publication;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    Http::preventStrayRequests();
});

/**
 * Отправленная публикация с одной доставкой.
 *
 * @param  array<string, mixed>  $delivery
 * @param  array<string, mixed>  $publication
 */
function sentPublication(Channel $channel, array $delivery, array $publication = []): Publication
{
    $sent = Publication::query()->create(['text' => '**Новый** текст', 'channel_ids' => [(string) $channel->id], 'status' => 'sent', ...$publication]);
    $sent->deliveries()->create(['channel_id' => $channel->id, 'status' => 'sent', 'sent_at' => now(), ...$delivery]);

    return $sent;
}

/**
 * Действие у публикации.
 */
function channelAction(Publication $publication, string $action): TestResponse
{
    return test()->post(route('fastleo.resources.action', ['publications', $action]), ['ids' => [(string) $publication->id]]);
}

describe('update', function (): void {
    it('edits the text message of a Telegram post with the new button', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $publication = sentPublication(publishingChannel(), ['layout' => 'album+text', 'message_ids' => ['album' => ['1', '2'], 'text' => '3']], ['button_label' => 'Читать', 'button_url' => 'https://site.ru/b']);

        channelAction($publication, 'update-channels')->assertSessionHas('fastleo.success', 'Текст и кнопка обновлены в каналах.');

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/editMessageText') && $request['message_id'] === 3
            && $request['text'] === '<b>Новый</b> текст' && $request['reply_markup'] === ['inline_keyboard' => [[['text' => 'Читать', 'url' => 'https://site.ru/b']]]]);
    });

    it('edits the caption of a single photo and removes the button', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: message is not modified'], 400)]);
        $publication = sentPublication(publishingChannel(), ['layout' => 'photo', 'message_ids' => ['photo' => '7']]);

        channelAction($publication, 'update-channels')->assertSessionHas('fastleo.success');

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/editMessageCaption') && $request['caption'] === '<b>Новый</b> текст' && $request['reply_markup'] === ['inline_keyboard' => []]);
    });

    it('refuses a caption longer than Telegram allows', function (): void {
        Http::fake();
        $publication = sentPublication(publishingChannel(), ['layout' => 'photo', 'message_ids' => ['photo' => '7']], ['text' => str_repeat('слово ', 300)]);

        channelAction($publication, 'update-channels')->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'не длиннее 1024'));
        Http::assertNothingSent();
    });

    it('sends the whole set of attachments to Max and checks its answer', function (): void {
        $channel = publishingChannel(['messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => '-71234567']);
        Http::fake(['platform-api2.max.ru/messages*' => Http::sequence()->push(['success' => true])->push(['success' => false, 'message' => 'message.not.found'])]);
        $publication = sentPublication($channel, ['layout' => 'message', 'message_ids' => ['message' => 'mid.1'], 'media_tokens' => ['tok-1']], ['button_label' => 'Читать', 'button_url' => 'https://site.ru/b']);

        channelAction($publication, 'update-channels')->assertSessionHas('fastleo.success');
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT' && str_contains($request->url(), 'message_id=mid.1')
            && $request['attachments'] === [['type' => 'image', 'payload' => ['token' => 'tok-1']], ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'link', 'text' => 'Читать', 'url' => 'https://site.ru/b']]]]]]);

        channelAction($publication, 'update-channels')->assertSessionHas('fastleo.error', 'Обновлено не везде: Новости: message.not.found');
    });
});

describe('remove', function (): void {
    it('deletes all messages of a Telegram post and keeps the publication in the journal', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $publication = sentPublication(publishingChannel(), ['layout' => 'album+text', 'message_ids' => ['album' => ['1', '2'], 'text' => '3']]);

        channelAction($publication, 'remove-channels')->assertSessionHas('fastleo.success', 'Пост удалён из каналов.');

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/deleteMessages') && collect($request['message_ids'])->sort()->values()->all() === [1, 2, 3]);
        expect($publication->refresh()->status)->toBe('removed')->and(Delivery::query()->sole()->status)->toBe('removed');

        $this->delete(route('fastleo.resources.destroy', ['publications', $publication->id]))->assertRedirect();
        expect(Publication::query()->count())->toBe(0);
    });

    it('explains why a post could not be removed and keeps it as sent', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => "Bad Request: message can't be deleted"], 400)]);
        $publication = sentPublication(publishingChannel(), ['layout' => 'text', 'message_ids' => ['text' => '3']]);

        channelAction($publication, 'remove-channels')->assertSessionHas('fastleo.error', "Удалено не везде: Новости: Bad Request: message can't be deleted");

        expect(Delivery::query()->sole())->status->toBe('sent')->error->toBe("Bad Request: message can't be deleted")
            ->and($publication->refresh()->status)->toBe('sent');
    });

    it('deletes every Max message and checks the answer', function (): void {
        $channel = publishingChannel(['messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => '-71234567']);
        Http::fake(['platform-api2.max.ru/messages*' => Http::response(['success' => true])]);
        $publication = sentPublication($channel, ['layout' => 'message', 'message_ids' => ['message' => 'mid.1']]);

        channelAction($publication, 'remove-channels')->assertSessionHas('fastleo.success');

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'message_id=mid.1'));
    });

    it('cannot remove a post whose message number is unknown', function (): void {
        Http::fake();
        $publication = sentPublication(publishingChannel(), ['status' => 'unknown', 'message_ids' => null]);

        channelAction($publication, 'remove-channels')->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'Неизвестен номер сообщения'));
        Http::assertNothingSent();
    });
});
