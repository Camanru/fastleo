<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Publishing\Delivery;
use Fastleo\Publishing\Publication;
use Fastleo\Publishing\SendDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->me = admin();
    $this->actingAs($this->me, Fastleo::GUARD);
    Http::preventStrayRequests();
    $this->channel = publishingChannel();
    $this->publication = Publication::query()->create(['text' => 'Анонс', 'channel_ids' => [(string) $this->channel->id], 'user_id' => (string) $this->me->id]);
});

/**
 * Доставка публикации в канал по умолчанию.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingDelivery(array $attributes = []): Delivery
{
    return test()->publication->deliveries()->create(['channel_id' => test()->channel->id, 'status' => 'pending', ...$attributes]);
}

describe('claiming', function (): void {
    it('sends nothing when another job has already taken the delivery', function (): void {
        Http::fake();
        $delivery = pendingDelivery(['status' => 'sending']);

        dispatch_sync(new SendDelivery($delivery));

        Http::assertNothingSent();
        expect($delivery->refresh()->status)->toBe('sending');
    });

    it('does not send twice a delivery that has already gone', function (): void {
        Http::fake();
        $delivery = pendingDelivery(['status' => 'sent']);

        dispatch_sync(new SendDelivery($delivery));

        Http::assertNothingSent();
    });
});

describe('failures', function (): void {
    it('marks the delivery failed when the channel was switched off after the draft was sent', function (): void {
        Http::fake();
        $delivery = pendingDelivery();
        $this->channel->update(['enabled' => false]);

        dispatch_sync(new SendDelivery($delivery));

        Http::assertNothingSent();
        expect($delivery->refresh())->status->toBe('failed')->error->not->toBeEmpty()
            ->and($this->publication->refresh()->status)->toBe('failed')
            ->and(AdminNotification::query()->sole()->data)->toMatchArray(['title' => 'Публикация дошла не во все каналы']);
    });

    it('waits and tries again when the messenger asks to, up to the fifth attempt', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 9]], 429)]);
        $delivery = pendingDelivery();
        $job = new SendDelivery($delivery)->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(9);
        expect($delivery->refresh())->status->toBe('pending')->error->toBe('Too Many Requests')
            ->and(AdminNotification::query()->count())->toBe(0);
    });

    it('gives up on the fifth attempt', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 9]], 429)]);
        $delivery = pendingDelivery();
        $job = new SendDelivery($delivery)->withFakeQueueInteractions();
        $job->job->attempts = 5;

        $job->handle();

        $job->assertNotReleased();
        expect($delivery->refresh())->status->toBe('failed')->error->toBe('Too Many Requests');
    });

    it('reports an unexpected error to the site and gives the author only a short note', function (): void {
        Exceptions::fake();
        Http::fake();
        $delivery = pendingDelivery();
        DB::table('fastleo_channels')->where('id', $this->channel->id)->update(['token' => 'not-an-encrypted-value']);

        dispatch_sync(new SendDelivery($delivery));

        Exceptions::assertReportedCount(1);
        Http::assertNothingSent();
        expect($delivery->refresh())->status->toBe('failed')->error->toBe('Отправка прервалась. Проверьте канал и повторите.')
            ->and($this->publication->refresh()->status)->toBe('failed');
    });
});

describe('a job that crashed', function (): void {
    it('marks the hanging delivery as possibly delivered and tells the author', function (): void {
        $delivery = pendingDelivery(['status' => 'sending', 'started_at' => now()]);

        new SendDelivery($delivery)->failed(new RuntimeException('Таймаут'));

        expect($delivery->refresh())->status->toBe('unknown')->error->not->toBeEmpty()
            ->and($this->publication->refresh()->status)->toBe('unknown')
            ->and(AdminNotification::query()->sole()->data)->toMatchArray(['title' => 'Публикация дошла не во все каналы']);
    });

    it('leaves a delivery that is not hanging alone', function (): void {
        $delivery = pendingDelivery(['status' => 'sent']);

        new SendDelivery($delivery)->failed(null);

        expect($delivery->refresh()->status)->toBe('sent')->and($this->publication->refresh()->status)->toBe('sent');
    });

    it('does not fail when the publication is already deleted', function (): void {
        $delivery = pendingDelivery(['status' => 'sending']);
        $job = new SendDelivery($delivery);
        DB::table('fastleo_publication_deliveries')->delete();
        $this->publication->delete();

        $job->failed(new RuntimeException('Таймаут'));

        expect(Publication::query()->count())->toBe(0);
    });
});
