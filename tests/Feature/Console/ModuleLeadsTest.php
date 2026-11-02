<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use Fastleo\Fastleo;
use Fastleo\Notifications\MessageNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-leads-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($this->sandbox);
    Schema::dropIfExists('leads');
    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoLeads']);
});

afterEach(function (): void {
    Date::setTestNow();
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Поднять скопированный модуль «Заявки» в тестовом приложении: таблица, классы, маршруты, шаблоны.
 */
function bootCopiedLeads(object $test): void
{
    // Посетитель с постоянной сессией: метка времени формы привязана к её номеру.
    $test->leadSessionId = Str::random(40);
    $test->withCookie(config('session.cookie'), $test->leadSessionId);

    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    // Классы объявляются один раз на процесс: каждый тест копирует модуль в свою папку.
    $classes = [
        'app/Enums/LeadStatus.php' => 'App\\Enums\\LeadStatus',
        'app/Models/Lead.php' => 'App\\Models\\Lead',
        'app/Http/Requests/StoreLeadRequest.php' => 'App\\Http\\Requests\\StoreLeadRequest',
        'app/Http/Controllers/LeadController.php' => 'App\\Http\\Controllers\\LeadController',
        'app/Fastleo/LeadResource.php' => 'App\\FastleoLeads\\LeadResource',
        'app/Fastleo/Dashboard.php' => 'App\\FastleoLeads\\Dashboard',
        'database/factories/LeadFactory.php' => 'Database\\Factories\\LeadFactory',
    ];

    foreach ($classes as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file;
        }
    }

    Factory::guessFactoryNamesUsing(fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory');
    Route::middleware('web')->group($test->sandbox.'/routes/site/leads.php');
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/**
 * Метка времени формы, показанной посетителю `$secondsAgo` секунд назад.
 */
function leadFormToken(int $secondsAgo = 60, ?string $session = null): string
{
    return Crypt::encryptString((now()->timestamp - $secondsAgo).'|'.($session ?? test()->leadSessionId));
}

/**
 * Поля заявки с формы сайта.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function leadInput(array $extra = []): array
{
    return ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'form_token' => leadFormToken(), ...$extra];
}

it('copies the leads module without a form, connects its route and adds the dashboard widgets', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])
        ->expectsOutputToContain('app/Http/Controllers/LeadController.php')
        ->expectsOutputToContain('trustProxies')
        ->expectsOutputToContain('выполните php artisan migrate')
        ->assertSuccessful();

    expect($this->sandbox.'/resources')->not->toBeDirectory()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain("glob(__DIR__.'/site/*.php')")
        ->and(File::get($this->sandbox.'/app/Fastleo/Dashboard.php'))
        ->toContain("Stat::make('Новые заявки')")
        ->toContain('\\App\\Models\\Lead::query()')
        ->toContain("Chart::make('Заявки за 30 дней')");
});

it('keeps the lead and thanks the visitor when the notification to the admins fails', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    admin();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Почта недоступна.'));

    $this->from('/about')->post('/leads', leadInput())
        ->assertRedirect('/about')
        ->assertSessionHas('lead_sent');

    expect(Lead::query()->count())->toBe(1);
});

it('lets the administrator type a name no longer than the column holds', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();

    expect(File::get($this->sandbox.'/app/Fastleo/LeadResource.php'))->toContain("Text::make('name', 'Имя')->required()->maxLength(100)")
        ->and(File::get(glob($this->sandbox.'/database/migrations/*_create_leads_table.php')[0]))->toContain("string('name', 100)");
});

it('saves a lead from the form, tells the admins and sets the status itself', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    $admin = admin();
    Notification::fake();

    $this->from('/about')->post('/leads', leadInput(['email' => 'ivan@example.com', 'message' => 'Перезвоните']))
        ->assertRedirect('/about')
        ->assertSessionHas('lead_sent');

    $lead = Lead::query()->firstOrFail();

    expect($lead->name)->toBe('Иван')
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->read_at)->toBeNull()
        ->and($lead->source)->toBe('/about');

    Notification::assertSentTo($admin, MessageNotification::class);
});

it('ignores a status and a read mark sent by the visitor', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);

    $this->post('/leads', leadInput(['status' => 'done', 'read_at' => now()->toDateTimeString(), 'id' => 99, 'source' => 'https://evil.example']))->assertSessionHas('lead_sent');

    $lead = Lead::query()->firstOrFail();

    expect($lead->status)->toBe(LeadStatus::New)
        ->and($lead->read_at)->toBeNull()
        ->and($lead->id)->not->toBe(99)
        ->and($lead->source)->toBeNull();
});

it('validates the form and keeps the typed values', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);

    $this->post('/leads', leadInput(['name' => '', 'phone' => 'abc', 'email' => 'не почта']))
        ->assertSessionHasErrors(['name', 'phone', 'email']);

    expect(Lead::query()->count())->toBe(0);
});

it('silently drops bots: filled trap, missing, forged or foreign-session token', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    Notification::fake();
    admin();

    foreach ([
        leadInput(['leave_empty' => 'http://spam.example']),
        leadInput(['form_token' => 'подделка']),
        leadInput(['form_token' => leadFormToken(60, 'чужая-сессия')]),
        ['name' => 'Бот', 'phone' => '+7 900 000-00-00'],
    ] as $input) {
        $this->post('/leads', $input)->assertSessionHas('lead_sent');
    }

    expect(Lead::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('asks a living visitor to repeat when the form was sent too fast or is stale, and keeps the input', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    Notification::fake();
    admin();

    foreach ([0, 86401] as $secondsAgo) {
        $this->post('/leads', leadInput(['name' => 'Мария', 'form_token' => leadFormToken($secondsAgo)]))
            ->assertSessionHasErrors('form_token')
            ->assertSessionMissing('lead_sent')
            ->assertSessionHasInput('name', 'Мария');
    }

    expect(Lead::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('limits the number of forms from one address without sharing the counter with other limited routes', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    Route::middleware('throttle:5,10')->get('/other', fn (): string => 'ok');

    foreach (range(1, 5) as $ignored) {
        $this->post('/leads', leadInput())->assertSessionHas('lead_sent');
    }

    $this->post('/leads', leadInput())->assertTooManyRequests();
    $this->get('/other')->assertOk();
});

it('gives the form a token that is bound to the session of the visitor', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    Route::middleware('web')->get('/token', fn (): string => StoreLeadRequest::token());

    $token = $this->get('/token')->assertOk()->getContent();

    expect(Crypt::decryptString((string) $token))->toBe(now()->timestamp.'|'.$this->leadSessionId);
});

it('gives the administrator a leads section with unread marks that passes fastleo:doctor', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    Fastleo::resources()->flush();
    $this->actingAs(admin(), Fastleo::GUARD);
    $lead = Lead::factory()->create(['name' => 'Пётр']);

    $this->get(route('fastleo.resources.index', 'leads'))->assertOk()->assertSee('Пётр');
    $this->get(route('fastleo.resources.show', ['leads', $lead->id]))->assertOk();

    expect($lead->fresh()->read_at)->not->toBeNull();

    $this->get(route('fastleo.dashboard'))->assertOk()->assertSee('Новые заявки');

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('scope', 'leads')->where('level', 'error');

    expect($problems->all())->toBe([]);
});

it('keeps only a valid path of the page as the source and links the admin to the lead by an absolute address', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['leads']])->assertSuccessful();
    bootCopiedLeads($this);
    $admin = admin();
    Notification::fake();

    $this->withHeader('Referer', 'https://site.test/about?x=1')->post('/leads', leadInput())->assertSessionHas('lead_sent');
    $this->withHeader('Referer', "https://site.test/\xff\xfe")->post('/leads', leadInput(['name' => 'Пётр']))->assertSessionHas('lead_sent');

    expect(Lead::query()->where('name', 'Иван')->value('source'))->toBe('/about')
        ->and(Lead::query()->where('name', 'Пётр')->value('source'))->toBeNull();

    Notification::assertSentTo($admin, MessageNotification::class, fn (MessageNotification $notification): bool => str_starts_with((string) $notification->toFastleo($admin)->toArray()['url'], 'http'));
});
