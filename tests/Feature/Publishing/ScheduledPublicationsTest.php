<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Publishing\Delivery;
use Fastleo\Publishing\Publication;
use Fastleo\Publishing\SendDelivery;
use Fastleo\Publishing\SendPublication;
use Fastleo\Publishing\StartPublication;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->me = admin();
    $this->actingAs($this->me, Fastleo::GUARD);
    Http::preventStrayRequests();
    $this->channel = publishingChannel();
    $this->publication = Publication::query()->create(['text' => 'Анонс', 'channel_ids' => [(string) $this->channel->id]]);
});

/**
 * «Отправить» с временем в часовом поясе админки.
 */
function sendAt(Publication $publication, string $at): TestResponse
{
    return test()->post(route('fastleo.resources.action', ['publications', 'send']), ['ids' => [(string) $publication->id], 'data' => ['send_at' => $at]]);
}

it('waits for the time in pieces of at most 15 minutes and then starts the sending', function (): void {
    Queue::fake();
    $this->travelTo(now()->setTime(12, 0));

    sendAt($this->publication, now()->addHour()->timezone(Fastleo::timezone())->format('Y-m-d\TH:i'))->assertSessionHas('fastleo.success');

    expect($this->publication->refresh())->status->toBe('scheduled')->scheduled_at->toDateTimeString()->toBe(now()->addHour()->toDateTimeString())
        ->and(Delivery::query()->sole()->status)->toBe('pending');
    Queue::assertPushed(StartPublication::class, fn (StartPublication $job): bool => $job->delay !== null && now()->diffInSeconds($job->delay) === 900.0);
    Queue::assertNotPushed(SendDelivery::class);

    $this->travel(15)->minutes();
    new StartPublication($this->publication, $this->publication->scheduled_at->toIso8601String())->handle();
    Queue::assertPushed(StartPublication::class, 2);
    Queue::assertNotPushed(SendDelivery::class);

    $this->travel(45)->minutes();
    new StartPublication($this->publication, $this->publication->scheduled_at->toIso8601String())->handle();
    Queue::assertPushed(SendDelivery::class, 1);
    expect($this->publication->refresh()->status)->toBe('sending');
});

it('does nothing when the publication was cancelled or moved to another time', function (): void {
    Queue::fake();
    sendAt($this->publication, now()->addMinutes(10)->timezone(Fastleo::timezone())->format('Y-m-d\TH:i'));
    $job = new StartPublication($this->publication, $this->publication->refresh()->scheduled_at->toIso8601String());

    $this->post(route('fastleo.resources.action', ['publications', 'cancel']), ['ids' => [(string) $this->publication->id]])->assertSessionHas('fastleo.success');
    expect($this->publication->refresh())->status->toBe('draft')->scheduled_at->toBeNull()
        ->and(Delivery::query()->count())->toBe(0);

    sendAt($this->publication, now()->addMinutes(30)->timezone(Fastleo::timezone())->format('Y-m-d\TH:i'));
    $this->travel(20)->minutes();
    $job->handle();

    Queue::assertNotPushed(SendDelivery::class);
    expect($this->publication->refresh()->status)->toBe('scheduled');
});

it('refuses a time in the past', function (): void {
    Queue::fake();

    sendAt($this->publication, now()->subMinute()->timezone(Fastleo::timezone())->format('Y-m-d\TH:i'))->assertSessionHas('fastleo.error', 'Это время уже прошло: укажите будущее или оставьте поле пустым.');

    expect($this->publication->refresh()->status)->toBe('draft');
});

it('has no time field and sends at once when the queue cannot wait', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->get(route('fastleo.resources.show', ['publications', $this->publication->id]))->assertDontSee('name="data[send_at]"', false);
    sendAt($this->publication, now()->addHour()->timezone(Fastleo::timezone())->format('Y-m-d\TH:i'))->assertSessionHas('fastleo.success', 'Опубликовано.');

    expect($this->publication->refresh()->status)->toBe('sent');
});

it('shows the time field when the queue can wait', function (): void {
    Queue::fake();

    $this->get(route('fastleo.resources.show', ['publications', $this->publication->id]))->assertSee('name="data[send_at]"', false);
});

it('refuses to send while the queue throws jobs away', function (): void {
    config(['queue.default' => 'null']);

    $this->post(route('fastleo.resources.action', ['publications', 'send']), ['ids' => [(string) $this->publication->id]])
        ->assertSessionHas('fastleo.error', fn (string $message): bool => str_contains($message, 'QUEUE_CONNECTION=null'));

    expect(Delivery::query()->count())->toBe(0);
});

it('lets the admin send an overdue scheduled publication by hand', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $this->publication->forceFill(['status' => 'scheduled', 'scheduled_at' => now()->subMinutes(10)])->save();
    $this->publication->deliveries()->create(['channel_id' => $this->channel->id]);

    $this->get(route('fastleo.resources.show', ['publications', $this->publication->id]))->assertSee('Отправить сейчас');
    $this->post(route('fastleo.resources.action', ['publications', 'send-now']), ['ids' => [(string) $this->publication->id]]);

    expect($this->publication->refresh())->status->toBe('sent')->scheduled_at->toBeNull();
});

it('puts the sending in the queue and says so when the time is not set', function (): void {
    Queue::fake();

    $this->post(route('fastleo.resources.action', ['publications', 'send']), ['ids' => [(string) $this->publication->id]])
        ->assertSessionHas('fastleo.success', 'Отправка началась: результат будет в журнале.');

    Queue::assertPushed(SendDelivery::class, 1);
    expect($this->publication->refresh()->status)->toBe('sending');
});

it('does not send a publication whose channels were all switched off', function (): void {
    Queue::fake();
    $this->channel->update(['enabled' => false]);

    $this->post(route('fastleo.resources.action', ['publications', 'send']), ['ids' => [(string) $this->publication->id]])
        ->assertSessionHas('fastleo.error', 'Выберите хотя бы один включённый канал.');

    Queue::assertNothingPushed();
    expect($this->publication->refresh()->status)->toBe('draft')->and(Delivery::query()->count())->toBe(0);
});

it('does nothing when the action got no publication', function (): void {
    Queue::fake();

    $result = new SendPublication()->handle(new EloquentCollection, []);

    expect($result->type)->toBe('error');
    Queue::assertNothingPushed();
});

it('waits for nothing when the publication has no time', function (): void {
    Queue::fake();

    StartPublication::schedule($this->publication);

    Queue::assertNothingPushed();
});
