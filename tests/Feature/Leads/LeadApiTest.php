<?php

declare(strict_types=1);

use Fastleo\Closing\SiteClosing;
use Fastleo\Fastleo;
use Fastleo\Leads\Lead;
use Fastleo\Leads\LeadApiController;
use Fastleo\Leads\LeadApiKey;
use Fastleo\Leads\LeadApiKeyResource;
use Fastleo\Leads\Leads;
use Fastleo\Leads\LeadStatus;
use Fastleo\Notifications\MessageNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    // Laravel не проверяет CSRF в окружении testing — проверяем как на боевом сайте.
    $this->app['env'] = 'production';
    $this->key = LeadApiKey::query()->create(['name' => 'Лендинг про шины', 'site' => 'https://shiny.example']);
    $this->token = $this->key->token;
    Cache::flush();
});

/**
 * Отправить заявку по API с ключом `$token` (по умолчанию — ключ теста).
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function sendLead(array $data = [], ?string $token = null, array $headers = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.($token ?? test()->token), ...$headers])
        ->postJson('/api/leads', ['phone' => '+7 900 123-45-67', 'name' => 'Иван', ...$data]);
}

describe('accepting a lead', function (): void {
    it('creates a lead without a csrf token and without a session or a cookie', function (): void {
        $response = sendLead(['message' => 'Нужны шины R16', 'page' => 'https://shiny.example/zimnie', 'form' => 'Обратный звонок', 'email' => 'ivan@example.com']);

        $response->assertCreated()->assertJsonPath('status', 'created')->assertHeader('Cache-Control');
        $lead = Lead::query()->sole();

        expect($response->json('result'))->toBe($lead->id)
            ->and($lead->name)->toBe('Иван')
            ->and($lead->phone)->toBe('+7 900 123-45-67')
            ->and($lead->email)->toBe('ivan@example.com')
            ->and($lead->message)->toBe('Нужны шины R16')
            ->and($lead->source)->toBe('https://shiny.example/zimnie')
            ->and($lead->site)->toBe('Лендинг про шины')
            ->and($lead->form)->toBe('Обратный звонок')
            ->and($lead->status)->toBe(LeadStatus::New)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->getCookies())->toBe([])
            ->and($response->headers->has('Set-Cookie'))->toBeFalse();
    });

    it('answers in JSON even when the client did not ask for it', function (): void {
        $this->post('/api/leads', ['phone' => '+7 900 123-45-67'])->assertUnauthorized()->assertJsonPath('status', 'unauthorized');

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->post('/api/leads', ['phone' => 'мусор'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone'])
            ->assertHeaderMissing('Location');
    });

    it('measures the length of an email before it parses it', function (): void {
        $response = sendLead(['email' => str_repeat('a', 300)])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        expect($response->json('errors.email'))->toHaveCount(1);
    });

    it('takes a phone sent as a number and fills in a missing name', function (): void {
        sendLead(['phone' => 79001234567, 'name' => null])->assertCreated();

        expect(Lead::query()->sole())->phone->toBe('79001234567')->name->toBe('Без имени');
    });

    it('checks the fields: phone is required, text has no NUL, the page is a web address, nothing is an array', function (array $data): void {
        sendLead($data)->assertUnprocessable();

        expect(Lead::query()->count())->toBe(0);
    })->with([
        'no phone' => [['phone' => '']],
        'bad phone' => [['phone' => 'abc']],
        'NUL in the message' => [['message' => "при\0вет"]],
        'a page that is not a web address' => [['page' => 'javascript:alert(1)']],
        'a page that is too long' => [['page' => 'https://shiny.example/'.str_repeat('a', 500)]],
        'an array instead of a string' => [['name' => ['a']]],
        'a bad email' => [['email' => 'не почта']],
        'a long message' => [['message' => str_repeat('я', 2001)]],
    ]);

    it('ignores fields it does not know, so the status and the site cannot be set from outside', function (): void {
        sendLead(['status' => 'done', 'site' => 'чужой', 'read_at' => now()->toDateTimeString(), 'id' => 99])->assertCreated();

        $lead = Lead::query()->sole();

        expect($lead->status)->toBe(LeadStatus::New)
            ->and($lead->site)->toBe('Лендинг про шины')
            ->and($lead->read_at)->toBeNull()
            ->and($lead->id)->not->toBe(99);
    });

    it('does not create a second lead for the same text within ten minutes, but does for another site or another text', function (): void {
        sendLead(['message' => 'Один'])->assertCreated();
        sendLead(['message' => 'Один'])->assertOk()->assertExactJson(['status' => 'duplicate']);
        sendLead(['message' => 'Два'])->assertCreated();

        $other = LeadApiKey::query()->create(['name' => 'Другой сайт']);
        sendLead(['message' => 'Один'], $other->token)->assertCreated();

        expect(Lead::query()->count())->toBe(3);

        $this->travel(11)->minutes();
        sendLead(['message' => 'Один'])->assertCreated();
    });

    it('notifies the administrators without the message, the form or the page and does not touch the form counter', function (): void {
        Notification::fake();
        config(['fastleo.notifications.bell' => true]);
        $admin = admin();

        sendLead(['message' => 'секрет', 'form' => 'secret-form', 'page' => 'https://shiny.example/p'])->assertCreated();

        Notification::assertSentTo($admin, MessageNotification::class, function (MessageNotification $notification) use ($admin): bool {
            $body = (string) $notification->toFastleo($admin)->toArray()['body'];

            return str_contains($body, 'Иван') && ! str_contains($body, 'секрет') && ! str_contains($body, 'secret-form') && ! str_contains($body, 'shiny');
        });

        expect(RateLimiter::attempts('leads-notify'))->toBe(0)
            ->and(RateLimiter::attempts('leads-notify-api:'.$this->key->id))->toBe(1);
    });

    it('counts the notifications of every key apart, so a leaked key does not silence the other sites', function (): void {
        Notification::fake();
        config(['fastleo.notifications.bell' => true]);
        $admin = admin();
        $other = LeadApiKey::query()->create(['name' => 'Другой сайт']);

        foreach (range(1, 20) as $number) {
            sendLead(['phone' => '+7 900 000-00-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT)])->assertCreated();
        }

        Notification::fake();
        sendLead(['phone' => '+7 900 000-00-99'], $other->token)->assertCreated();

        Notification::assertSentTo($admin, MessageNotification::class);
    });

    it('refuses without a key when called directly, not through the route', function (): void {
        expect(fn () => resolve(LeadApiController::class)->store(Request::create('/api/leads', 'POST', ['phone' => '+7 900 123-45-67'])))
            ->toThrow(HttpException::class);
    });
});

describe('the key', function (): void {
    it('is taken only from the Authorization header, not from the address or the body', function (): void {
        $this->postJson('/api/leads?key='.$this->token, ['phone' => '+7 900 123-45-67', 'key' => $this->token, 'api_key' => $this->token, 'token' => $this->token])->assertUnauthorized();

        expect(Lead::query()->count())->toBe(0);
    });

    it('is rejected with one and the same answer when it is missing, wrong, of a wrong form or switched off', function (): void {
        $this->key->forceFill(['is_active' => false])->save();
        $answers = [
            $this->postJson('/api/leads', ['phone' => '+7 900 123-45-67']),
            sendLead([], 'fllead_'.str_repeat('a', 40)),
            sendLead([], 'мусор'),
            sendLead([], $this->token),
        ];

        foreach ($answers as $answer) {
            $answer->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')->assertJsonPath('status', 'unauthorized');
        }

        expect($answers[0]->json())->toBe($answers[3]->json());
    });

    it('is stored encrypted with a hash for the search and never shown when the model is turned into an array', function (): void {
        $row = (array) DB::table('fastleo_lead_api_keys')->first();

        expect($row['token'])->not->toBe($this->token)
            ->and($row['token_hash'])->toBe(hash('sha256', $this->token))
            ->and($this->token)->toStartWith('fllead_')
            ->and($this->key->fresh()->toArray())->not->toHaveKeys(['token', 'token_hash'])
            ->and(LeadApiKey::findActive($this->token)?->is($this->key))->toBeTrue()
            ->and(LeadApiKey::findActive('fllead_'.str_repeat('a', 40)))->toBeNull();
    });

    it('is replaced by regenerating: the old one stops working at once', function (): void {
        $old = $this->token;
        $this->key->issue()->save();

        sendLead([], $old)->assertUnauthorized();
        sendLead([], $this->key->token)->assertCreated();

        expect($this->key->token)->not->toBe($old);
    });

    it('does not break the page when the application key has changed, and says what to do', function (): void {
        DB::table('fastleo_lead_api_keys')->where('id', $this->key->id)->update(['token' => 'не расшифровывается']);

        expect($this->key->fresh()->visible_token)->toContain('Перевыпустите')
            ->and($this->key->fresh()->instruction)->toContain('Перевыпустите');
    });

    it('writes the time of use at most once a minute', function (): void {
        sendLead()->assertCreated();
        $first = $this->key->fresh()->last_used_at;

        expect($first)->not->toBeNull();

        $this->travel(30)->seconds();
        sendLead(['message' => 'ещё'])->assertCreated();

        expect($this->key->fresh()->last_used_at?->equalTo($first))->toBeTrue();

        $this->travel(40)->seconds();
        sendLead(['message' => 'и ещё'])->assertCreated();

        expect($this->key->fresh()->last_used_at?->gt($first))->toBeTrue();
    });
});

describe('limits', function (): void {
    it('allows thirty requests a minute for a key and then answers 429 with the time to wait', function (): void {
        foreach (range(1, 30) as $number) {
            sendLead(['message' => 'M'.$number])->assertCreated();
        }

        sendLead(['message' => 'ещё'])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('status', 'too_many_requests');
    });

    it('allows a thousand requests a day for a key', function (): void {
        RateLimiter::increment('lead-api-day:'.$this->key->id, 86400, 1000);

        sendLead()->assertStatus(429);
    });

    it('counts failed attempts only: twenty a minute from one address, and a real key is not touched by them', function (): void {
        foreach (range(1, 20) as $_) {
            sendLead([], 'fllead_'.str_repeat('b', 40))->assertUnauthorized();
        }

        sendLead([], 'fllead_'.str_repeat('b', 40))->assertStatus(429)->assertHeader('Retry-After');
        sendLead()->assertCreated();
    });

    it('does not let attempts of one address take the allowance of another', function (): void {
        foreach (range(1, 21) as $_) {
            sendLead([], 'fllead_'.str_repeat('b', 40));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/leads', ['phone' => '+7 900 123-45-67'], ['Authorization' => 'Bearer fllead_'.str_repeat('b', 40)])->assertUnauthorized();
    });

    it('refuses a body over 16 KB, with or without the length header', function (): void {
        sendLead(['message' => str_repeat('a', 17000)])->assertStatus(413)->assertJsonPath('status', 'too_large');

        $this->call('POST', '/api/leads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], json_encode(['phone' => '+7 900 123-45-67', 'message' => str_repeat('a', 17000)]))
            ->assertStatus(413);

        expect(Lead::query()->count())->toBe(0);
    });
});

describe('the site of the request', function (): void {
    it('accepts a browser request from the site written for the key, with or without www, and refuses another site', function (): void {
        sendLead(['message' => 'a'], null, ['Origin' => 'https://shiny.example'])->assertCreated();
        sendLead(['message' => 'b'], null, ['Origin' => 'https://www.shiny.example'])->assertCreated();
        sendLead(['message' => 'c'], null, ['Origin' => 'https://evil.example'])->assertForbidden()->assertJsonPath('status', 'forbidden');
        sendLead(['message' => 'd'], null, ['Origin' => 'null'])->assertForbidden();
    });

    it('does not check a request from a server, which has no Origin, and an open key', function (): void {
        sendLead(['message' => 'a'])->assertCreated();

        $open = LeadApiKey::query()->create(['name' => 'Открытый']);
        sendLead(['message' => 'b'], $open->token, ['Origin' => 'https://anything.example'])->assertCreated();
    });

    it('takes the site written as a bare host', function (): void {
        $this->key->forceFill(['site' => 'Shiny.Example'])->save();

        sendLead(['message' => 'a'], null, ['Origin' => 'https://shiny.example'])->assertCreated();
        sendLead(['message' => 'b'], null, ['Origin' => 'https://shiny.example.evil'])->assertForbidden();
    });
});

describe('where the route lives', function (): void {
    it('has the route only while the leads and the keys are on, and the address can be changed', function (): void {
        expect(Route::has('fastleo-leads.api'))->toBeTrue()
            ->and(Leads::apiEnabled())->toBeTrue()
            ->and(Leads::formEnabled())->toBeTrue();

        config(['fastleo.leads.keys' => null]);
        expect(Leads::apiEnabled())->toBeFalse()->and(Leads::formEnabled())->toBeTrue();

        config(['fastleo.leads.keys' => LeadApiKeyResource::class, 'fastleo.leads.form' => false]);
        expect(Leads::formEnabled())->toBeFalse()->and(Leads::apiEnabled())->toBeTrue();

        config(['fastleo.leads.resource' => null]);
        expect(Leads::enabled())->toBeFalse()->and(Leads::apiEnabled())->toBeFalse()->and(Leads::formEnabled())->toBeFalse();
    });

    it('lets the client retry when the lead could not be saved', function (): void {
        $failures = 1;
        Lead::creating(function () use (&$failures): void {
            if ($failures-- > 0) {
                throw new RuntimeException('база недоступна');
            }
        });

        $this->withoutExceptionHandling();
        expect(fn (): TestResponse => sendLead(['message' => 'Нужны шины']))->toThrow(RuntimeException::class);
        $this->withExceptionHandling();

        // Повтор — не «duplicate»: заявка не была создана, метка снята.
        sendLead(['message' => 'Нужны шины'])->assertCreated();
        expect(Lead::query()->count())->toBe(1);
    });

    it('keeps the keys section when the site config sets only the resource of the leads block', function (): void {
        // Конфиг пакета сливается по первому уровню: блок `leads` сайта целиком заменяет блок пакета, ключей в нём нет.
        config(['fastleo.leads' => ['resource' => config('fastleo.leads.resource')]]);

        expect(Leads::keysClass())->toBe(LeadApiKeyResource::class)
            ->and(Leads::apiEnabled())->toBeTrue()
            ->and(Leads::formEnabled())->toBeTrue()
            ->and(Fastleo::resources()->builtIn())->toContain(LeadApiKeyResource::class);
    });

    it('answers 503 in JSON while the site is closed', function (): void {
        // Закрытие сайта настраивается из панели, где CSRF проверяется только на боевом окружении, — настраиваем как в тестах панели.
        $this->app['env'] = 'testing';
        config(['fastleo.closing.enabled' => true]);
        SiteClosing::register();
        Fastleo::resources()->flush();
        $this->actingAs(admin(), Fastleo::GUARD)->put(route('fastleo.settings.update', 'closing'), ['closed' => '1', 'message' => 'Скоро вернёмся'])->assertSessionHasNoErrors();
        auth(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->app['env'] = 'production';

        sendLead()->assertStatus(503)->assertJsonPath('message', 'Скоро вернёмся');

        expect(Lead::query()->count())->toBe(0);
    });

    it('has the route in the api group, outside the web group with its session and csrf', function (): void {
        $route = Route::getRoutes()->getByName('fastleo-leads.api');
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('api')
            ->not->toContain('web')
            ->and($route->methods())->toContain('POST');
    });
});
