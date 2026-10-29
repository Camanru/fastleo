<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\RunAction;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Publishing\Delivery;
use Fastleo\Publishing\Publication;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->me = admin();
    $this->actingAs($this->me, Fastleo::GUARD);
    Http::preventStrayRequests();
    Storage::fake('public');
    $this->channel = publishingChannel();
});

/**
 * Публикация с текстом и каналом по умолчанию.
 *
 * @param  array<string, mixed>  $attributes
 */
function publication(array $attributes = []): Publication
{
    return Publication::query()->create(['text' => '**Акция** до пятницы', 'channel_ids' => [(string) test()->channel->id], 'user_id' => (string) test()->me->id, ...$attributes]);
}

/**
 * Картинки на диске файлов.
 *
 * @return list<string>
 */
function publicationImages(int $count): array
{
    $paths = [];

    for ($index = 1; $index <= $count; $index++) {
        Storage::disk('public')->put($path = 'uploads/publications/photo-'.$index.'.jpg', 'jpeg-'.$index);
        $paths[] = $path;
    }

    return $paths;
}

/**
 * Нажать «Отправить» (или другое действие) у публикации.
 */
function publicationAction(Publication $publication, string $action = 'send'): TestResponse
{
    return test()->post(route('fastleo.resources.action', ['publications', $action]), ['ids' => [(string) $publication->id]]);
}

/**
 * Часть multipart-запроса по имени (у файла — имя файла).
 */
function part(HttpRequest $request, string $name): mixed
{
    foreach ($request->data() as $part) {
        if (is_array($part) && ($part['name'] ?? null) === $name) {
            return $part['filename'] ?? $part['contents'];
        }
    }

    return null;
}

/**
 * Ответ Telegram «сообщение отправлено».
 */
function telegramMessage(int $id): array
{
    return ['ok' => true, 'result' => ['message_id' => $id]];
}

describe('section', function (): void {
    it('is in the menu only while there is an enabled channel', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.resources.index', 'publications'), false);

        $this->channel->update(['enabled' => false]);

        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.resources.index', 'publications'), false);
        $this->get(route('fastleo.resources.index', 'publications'))->assertOk();
    });

    it('saves a free post as a draft', function (): void {
        $this->post(route('fastleo.resources.store', 'publications'), [
            'text' => 'Привет', 'images' => [], 'button_label' => 'Читать', 'button_url' => 'https://site.ru/news/1', 'channel_ids' => [(string) $this->channel->id],
        ])->assertSessionHasNoErrors();

        expect(Publication::query()->sole())->status->toBe('draft')->button_url->toBe('https://site.ru/news/1');
    });

    it('checks the text, the button and the channels', function (): void {
        $this->post(route('fastleo.resources.store', 'publications'), ['text' => str_repeat('а', 4001), 'button_label' => 'Читать', 'button_url' => 'javascript:alert(1)', 'channel_ids' => []])
            ->assertSessionHasErrors(['text' => 'Текст не помещается в сообщение мессенджера: сократите его до 4000 символов.', 'button_url', 'channel_ids']);

        $this->post(route('fastleo.resources.store', 'publications'), ['text' => 'Привет', 'button_label' => 'Читать', 'channel_ids' => [(string) $this->channel->id]])
            ->assertSessionHasErrors('button_url');
    });
});

describe('telegram', function (): void {
    it('sends a text with the button as one message in HTML', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(telegramMessage(10))]);
        $publication = publication(['button_label' => 'Читать', 'button_url' => 'https://site.ru/a']);

        publicationAction($publication)->assertSessionHas('fastleo.success', 'Опубликовано.');

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '-1001234567890'
            && $request['text'] === '<b>Акция</b> до пятницы'
            && $request['parse_mode'] === 'HTML'
            && $request['reply_markup'] === ['inline_keyboard' => [[['text' => 'Читать', 'url' => 'https://site.ru/a']]]]);
        expect($publication->refresh()->status)->toBe('sent')
            ->and(Delivery::query()->sole())->status->toBe('sent')->layout->toBe('text')->message_ids->toBe(['text' => '10']);
    });

    it('puts a short text under a single photo', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(telegramMessage(11))]);
        $publication = publication(['images' => publicationImages(1), 'button_label' => 'Читать', 'button_url' => 'https://site.ru/a']);

        publicationAction($publication);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendPhoto') && part($request, 'caption') === '<b>Акция</b> до пятницы' && part($request, 'photo') === 'photo-1.jpg' && str_contains((string) part($request, 'reply_markup'), 'https://site.ru/a'));
        expect(Delivery::query()->sole())->layout->toBe('photo')->message_ids->toBe(['photo' => '11']);
    });

    it('sends a long text after a single photo', function (): void {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(telegramMessage(1))->push(telegramMessage(2))]);
        $publication = publication(['images' => publicationImages(1), 'text' => str_repeat('слово ', 200)]);

        publicationAction($publication);

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendPhoto') && part($request, 'caption') === null);
        expect(Delivery::query()->sole())->layout->toBe('photo+text')->message_ids->toBeStoredJson(['photo' => '1', 'text' => '2']);
    });

    it('sends an album and then the text with the button', function (): void {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [['message_id' => 20], ['message_id' => 21]]])
            ->push(telegramMessage(22))]);
        $publication = publication(['images' => publicationImages(2), 'button_label' => 'Читать', 'button_url' => 'https://site.ru/a']);

        publicationAction($publication);

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMediaGroup') && part($request, 'p0') === 'photo-1.jpg' && part($request, 'p1') === 'photo-2.jpg'
            && json_decode((string) part($request, 'media'), true) === [['type' => 'photo', 'media' => 'attach://p0'], ['type' => 'photo', 'media' => 'attach://p1']]);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage') && isset($request['reply_markup']) && $request['link_preview_options'] === ['is_disabled' => true]);
        expect(Delivery::query()->sole())->layout->toBe('album+text')->message_ids->toBeStoredJson(['album' => ['20', '21'], 'text' => '22']);
    });

    it('does not send the album again when only the text failed', function (): void {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [['message_id' => 20], ['message_id' => 21]]])
            ->push(['ok' => false, 'description' => 'Bad Request: can\'t parse entities'], 400)
            ->push(telegramMessage(22))]);
        $publication = publication(['images' => publicationImages(2)]);

        publicationAction($publication)->assertSessionHas('fastleo.error', 'Не отправлено');
        expect(Delivery::query()->sole())->status->toBe('failed')->error->toBe("Bad Request: can't parse entities");

        publicationAction($publication->refresh(), 'retry')->assertSessionHas('fastleo.success');

        Http::assertSentCount(3);
        expect(Delivery::query()->sole())->status->toBe('sent')->message_ids->toBeStoredJson(['album' => ['20', '21'], 'text' => '22'])
            ->and($publication->refresh()->status)->toBe('sent');
    });

    it('does not retry by itself when the connection broke after sending and tells the author', function (): void {
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $publication = publication();

        publicationAction($publication);

        expect(Delivery::query()->sole())->status->toBe('unknown')
            ->and($publication->refresh()->status)->toBe('unknown')
            ->and(AdminNotification::query()->sole()->data)->toMatchArray(['title' => 'Публикация дошла не во все каналы']);
    });

    it('reports a missing image file without asking Telegram', function (): void {
        Http::fake();
        $publication = publication(['images' => ['uploads/publications/gone.jpg']]);

        publicationAction($publication);

        Http::assertNothingSent();
        expect(Delivery::query()->sole()->error)->toBe('Нет файла картинки uploads/publications/gone.jpg.');
    });
});

describe('max', function (): void {
    it('uploads the images and sends one message with them and the button', function (): void {
        $channel = publishingChannel(['name' => 'Max', 'messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => '-71234567']);
        Http::fake([
            'platform-api2.max.ru/uploads*' => Http::response(['url' => 'https://iu.oneme.ru/upload?id=1']),
            'iu.oneme.ru/*' => Http::sequence()->push(['photos' => ['a' => ['token' => 'tok-1']]])->push(['photos' => ['b' => ['token' => 'tok-2']]]),
            'platform-api2.max.ru/messages*' => Http::response(['message' => ['body' => ['mid' => 'mid.1']]]),
        ]);
        $publication = publication(['channel_ids' => [(string) $channel->id], 'images' => publicationImages(2), 'button_label' => 'Читать', 'button_url' => 'https://site.ru/a']);

        publicationAction($publication)->assertSessionHas('fastleo.success', 'Опубликовано.');

        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'platform-api2.max.ru/messages?chat_id=-71234567')
            && $request['format'] === 'html'
            && $request['text'] === '<b>Акция</b> до пятницы'
            && $request['attachments'] === [
                ['type' => 'image', 'payload' => ['token' => 'tok-1']],
                ['type' => 'image', 'payload' => ['token' => 'tok-2']],
                ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'link', 'text' => 'Читать', 'url' => 'https://site.ru/a']]]]],
            ]);
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://iu.oneme.ru/') && $request->header('Authorization') === []);
        expect(Delivery::query()->sole())->media_tokens->toBe(['tok-1', 'tok-2'])->message_ids->toBe(['message' => 'mid.1']);
    });

    it('waits while Max is still processing the images', function (): void {
        Sleep::fake();
        $channel = publishingChannel(['name' => 'Max', 'messenger' => 'max', 'token' => MAX_TOKEN, 'chat_id' => '-71234567']);
        Http::fake(['platform-api2.max.ru/messages*' => Http::sequence()
            ->push(['code' => 'attachment.not.ready', 'message' => 'attachment.not.ready'], 400)
            ->push(['message' => ['body' => ['mid' => 'mid.2']]])]);

        publicationAction(publication(['channel_ids' => [(string) $channel->id]]));

        Sleep::assertSleptTimes(1);
        expect(Delivery::query()->sole()->status)->toBe('sent');
    });
});

describe('journal', function (): void {
    it('marks a publication partly sent when one of the channels failed', function (): void {
        $second = publishingChannel(['name' => 'Второй', 'chat_id' => '-100555']);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(telegramMessage(1))->push(['ok' => false, 'description' => 'chat not found'], 400)]);
        $publication = publication(['channel_ids' => [(string) $this->channel->id, (string) $second->id]]);

        publicationAction($publication)->assertSessionHas('fastleo.error', 'Опубликовано не во все каналы');

        $this->get(route('fastleo.resources.show', ['publications', $publication->id]))->assertOk()->assertSee('chat not found')->assertSee('опубликовано');
    });

    it('keeps a sent publication in the journal and locks its images and channels', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(telegramMessage(1))]);
        $publication = publication(['images' => publicationImages(1)]);
        publicationAction($publication);

        $this->delete(route('fastleo.resources.destroy', ['publications', $publication->id]))->assertForbidden();
        $this->delete(route('fastleo.resources.destroy', ['channels', $this->channel->id]))->assertForbidden();
        $this->get(route('fastleo.resources.edit', ['publications', $publication->id]))->assertOk()->assertDontSee('name="channel_ids[]"', false);
        $this->put(route('fastleo.resources.update', ['publications', $publication->id]), ['text' => 'Исправлено', 'channel_ids' => ['999']])->assertSessionHasNoErrors();

        expect($publication->refresh())->text->toBe('Исправлено')->channel_ids->toBe([(string) $this->channel->id]);
    });

    it('deletes a draft', function (): void {
        $publication = publication();

        $this->delete(route('fastleo.resources.destroy', ['publications', $publication->id]))->assertRedirect();

        expect(Publication::query()->count())->toBe(0);
    });

    it('lets AI agents edit drafts through MCP but not send them', function (): void {
        $publication = publication();

        FastleoServer::actingAs($this->me, 'fastleo')->tool(RunAction::class, ['resource' => 'publications', 'action' => 'send', 'ids' => [(string) $publication->id]])
            ->assertHasErrors(['Это действие запускает только человек в админке']);
        expect(Delivery::query()->count())->toBe(0);
    });
});
