<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\FastleoServiceProvider;
use Fastleo\Leads\Lead;
use Fastleo\Leads\LeadApiKey;
use Fastleo\Leads\LeadApiKeyResource;
use Fastleo\Leads\LeadResource;
use Fastleo\Leads\LeadStatus;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

afterEach(function (): void {
    Fastleo::resolveOwnerUsing(null);
});

describe('the section of leads', function (): void {
    it('lists the leads with the site and marks a lead as read when it is opened', function (): void {
        $lead = Lead::query()->create(['name' => 'Пётр', 'phone' => '+7 900 000-00-00', 'site' => 'Лендинг', 'source' => 'https://shiny.example/p', 'status' => LeadStatus::New]);

        $this->get(route('fastleo.resources.index', 'leads'))->assertOk()->assertSee('Пётр')->assertSee('Лендинг')->assertSee('Новая');

        $this->get(route('fastleo.resources.show', ['leads', $lead->id]))->assertOk()->assertSee('https://shiny.example/p');

        expect($lead->fresh()->read_at)->not->toBeNull();
    });

    it('lets the administrator change the status and the contacts, but not where the lead came from', function (): void {
        $lead = Lead::query()->create(['name' => 'Пётр', 'phone' => '+7 900 000-00-00', 'site' => 'Лендинг', 'source' => '/a', 'form' => 'Форма', 'status' => LeadStatus::New]);

        $this->put(route('fastleo.resources.update', ['leads', $lead->id]), ['name' => 'Пётр', 'phone' => '+7 900 000-00-00', 'status' => 'done', 'site' => 'Подмена', 'source' => 'https://evil.example', 'form' => 'Подмена'])
            ->assertSessionHasNoErrors();

        $lead->refresh();

        expect($lead->status)->toBe(LeadStatus::Done)
            ->and($lead->site)->toBe('Лендинг')
            ->and($lead->source)->toBe('/a')
            ->and($lead->form)->toBe('Форма');
    });

    it('stands in the top of the menu without a group', function (): void {
        expect(LeadResource::group())->toBeNull()->and(LeadApiKeyResource::group())->toBe('Настройки');
    });

    it('keeps the leads out of an export and MCP', function (): void {
        $this->get(route('fastleo.resources.export', 'leads'))->assertNotFound();

        expect(LeadResource::isExportable())->toBeFalse()
            ->and(LeadResource::isAvailableInMcp())->toBeFalse()
            ->and(LeadResource::isGloballySearchable())->toBeTrue();
    });

    it('warns in fastleo:doctor when a section of the site has taken the address of the leads', function (): void {
        useFixtureResources('Registry/TakenLeads');
        config(['fastleo.leads.resource' => LeadResource::class]);
        Fastleo::resources()->flush();

        Artisan::call('fastleo:doctor', ['--json' => true]);
        $problem = collect(json_decode(Artisan::output(), true)['problems'])->first(fn (array $problem): bool => str_contains($problem['message'], 'Адрес /leads занят разделом сайта'));

        expect($problem['level'] ?? null)->toBe('warning')->and($problem['hint'])->toContain('leads.resource');
    });

    it('passes fastleo:doctor with both sections on', function (): void {
        Artisan::call('fastleo:doctor', ['--json' => true]);
        $problems = collect(json_decode(Artisan::output(), true)['problems'])->whereIn('scope', ['leads', 'lead-api-keys'])->where('level', 'error');

        expect($problems->all())->toBe([]);
    });
});

describe('the section of keys', function (): void {
    it('creates a key from the panel, shows it with the instruction only on an existing record and hides it on the form', function (): void {
        $this->get(route('fastleo.resources.create', 'lead-api-keys'))->assertOk()->assertDontSee('Инструкция разработчику сайта');

        $this->post(route('fastleo.resources.store', 'lead-api-keys'), ['name' => 'Лендинг про шины', 'site' => 'https://shiny.example', 'is_active' => '1'])->assertSessionHasNoErrors();

        $key = LeadApiKey::query()->sole();

        expect($key->token)->toStartWith('fllead_');

        $this->get(route('fastleo.resources.edit', ['lead-api-keys', $key->id]))
            ->assertOk()
            ->assertSee($key->token)
            ->assertSee(url('/api/leads'))
            ->assertSee('ПОДКЛЮЧЕНИЕ САЙТА «Лендинг про шины»', false)
            ->assertSee('Запросы из браузера принимаются только со страниц сайта https://shiny.example.');
    });

    it('does not write the key itself from the form', function (): void {
        $key = LeadApiKey::query()->create(['name' => 'Сайт']);
        $token = $key->token;

        $this->put(route('fastleo.resources.update', ['lead-api-keys', $key->id]), ['name' => 'Сайт', 'is_active' => '1', 'token' => 'fllead_'.str_repeat('z', 40), 'token_hash' => str_repeat('0', 64), 'visible_token' => 'x'])
            ->assertSessionHasNoErrors();

        expect($key->fresh()->token)->toBe($token);
    });

    it('regenerates the key with an action: the old one stops working', function (): void {
        $key = LeadApiKey::query()->create(['name' => 'Сайт']);
        $old = $key->token;

        $this->post(route('fastleo.resources.action', ['lead-api-keys', 'regenerate']), ['ids' => [$key->id]])->assertSessionHas('fastleo.success');

        expect($key->fresh()->token)->not->toBe($old)
            ->and(LeadApiKey::findActive($old))->toBeNull()
            ->and(LeadApiKey::findActive($key->fresh()->token)?->is($key))->toBeTrue();
    });

    it('is closed to an export, an import, MCP and the shared search', function (): void {
        $this->get(route('fastleo.resources.export', 'lead-api-keys'))->assertNotFound();
        $this->post(route('fastleo.resources.import', 'lead-api-keys'))->assertNotFound();

        expect(LeadApiKeyResource::isExportable())->toBeFalse()
            ->and(LeadApiKeyResource::isImportable())->toBeFalse()
            ->and(LeadApiKeyResource::isAvailableInMcp())->toBeFalse()
            ->and(LeadApiKeyResource::isGloballySearchable())->toBeFalse();
    });

    it('is open to everyone while the site has neither roles nor an owner, and only to the owner once it has one', function (): void {
        $key = LeadApiKey::query()->create(['name' => 'Сайт']);

        $this->get(route('fastleo.resources.index', 'lead-api-keys'))->assertOk();

        Fastleo::resolveOwnerUsing(fn (User $user): bool => $user->email === 'owner@example.com');

        $this->get(route('fastleo.resources.index', 'lead-api-keys'))->assertForbidden();
        $this->get(route('fastleo.resources.edit', ['lead-api-keys', $key->id]))->assertForbidden();
        $this->post(route('fastleo.resources.action', ['lead-api-keys', 'regenerate']), ['ids' => [$key->id]])->assertForbidden();

        resolve('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs(admin(['email' => 'owner@example.com']), Fastleo::GUARD);

        $this->get(route('fastleo.resources.index', 'lead-api-keys'))->assertOk()->assertSee('Сайт');
    });
});

/**
 * Страница сайта с формой в группе `web`: в запросе есть ошибки сессии, которые форма показывает.
 */
function pageWith(string $template): string
{
    Route::middleware('web')->get('/page-with-form', fn (): string => Blade::render($template));

    return (string) test()->get('/page-with-form')->assertOk()->getContent();
}

describe('the form for the site', function (): void {
    it('draws a form with the token, the trap, the csrf field and the address of the route', function (): void {
        $html = pageWith('<x-fastleo::lead-form />');

        expect($html)->toContain('action="'.route('fastleo-leads.store').'"')
            ->toContain('name="form_token"')
            ->toContain('name="leave_empty"')
            ->toContain('name="_token"')
            ->toContain('name="name"')
            ->toContain('name="phone"')
            ->toContain('name="message"')
            ->not->toContain('name="email"')
            ->toContain('Отправить');
    });

    it('draws the optional fields and the labels given', function (): void {
        $html = pageWith('<x-fastleo::lead-form :with-email="true" :with-message="false" button="Заказать звонок" name-label="Ваше имя" class="lead-form" />');

        expect($html)->toContain('name="email"')
            ->not->toContain('name="message"')
            ->toContain('Заказать звонок')
            ->toContain('Ваше имя')
            ->toContain('class="lead-form"');
    });

    it('gives a form with an anchor its own id, error bag and hidden fields', function (): void {
        $html = pageWith('<x-fastleo::lead-form anchor="hero" form-name="Первый экран" class="lead-form" />');

        expect($html)->toContain('id="form-hero"')
            ->toContain('class="lead-form"')
            ->toContain('name="form_anchor" value="hero"')
            ->toContain('name="form" value="Первый экран"');
    });

    it('does not draw a broken anchor or an empty name of the form', function (): void {
        $html = pageWith('<x-fastleo::lead-form anchor="Bad Anchor" form-name="" />');

        expect($html)->not->toContain('form_anchor')
            ->not->toContain('id="form-')
            ->not->toContain('name="form"');
    });

    it('shows the errors and the typed values only in the form that was sent', function (): void {
        $this->withSession([
            'errors' => new ViewErrorBag()->put('lead_final', new MessageBag(['phone' => ['Проверьте телефон.']])),
            '_old_input' => ['form_anchor' => 'final', 'name' => 'Иван', 'phone' => 'abc'],
        ]);

        $html = pageWith('<x-fastleo::lead-form anchor="hero" /><x-fastleo::lead-form anchor="final" /><x-fastleo::lead-form />');

        expect(substr_count($html, 'Проверьте телефон.'))->toBe(1)
            ->and(substr_count($html, 'value="Иван"'))->toBe(1)
            ->and(strpos($html, 'Проверьте телефон.'))->toBeGreaterThan(strpos($html, 'id="form-final"'));
    });

    it('shows the error of the form name next to the form that was sent', function (): void {
        $this->withSession([
            'errors' => new ViewErrorBag()->put('lead_final', new MessageBag(['form' => ['Название формы не подошло.']])),
            '_old_input' => ['form_anchor' => 'final'],
        ]);

        $html = pageWith('<x-fastleo::lead-form anchor="hero" /><x-fastleo::lead-form anchor="final" />');

        expect(substr_count($html, 'Название формы не подошло.'))->toBe(1)
            ->and(strpos($html, 'Название формы не подошло.'))->toBeGreaterThan(strpos($html, 'id="form-final"'));
    });

    it('thanks only the form that was sent, and a form without an anchor only after a form without an anchor', function (): void {
        $this->withSession(['lead_sent' => true, 'lead_sent_anchor' => 'final']);

        $html = pageWith('<x-fastleo::lead-form anchor="hero" /><x-fastleo::lead-form anchor="final" /><x-fastleo::lead-form />');

        expect(substr_count($html, 'Спасибо! Заявка отправлена.'))->toBe(1)
            ->and(strpos($html, 'Спасибо! Заявка отправлена.'))->toBeGreaterThan(strpos($html, 'id="form-final"'))
            ->and(strpos($html, 'Спасибо! Заявка отправлена.'))->toBeLessThan(strrpos($html, '<form'));
    });

    it('thanks the visitor once the lead has been sent', function (): void {
        $this->withSession(['lead_sent' => true]);

        expect(pageWith('<x-fastleo::lead-form />'))->toContain('Спасибо! Заявка отправлена.');
    });
});

describe('boot', function (): void {
    it('adds the routes at boot only for what is switched on', function (): void {
        $boot = function (): void {
            Route::setRoutes(new RouteCollection());
            new FastleoServiceProvider($this->app)->boot();
            Route::getRoutes()->refreshNameLookups();
        };

        $boot();
        expect(Route::has('fastleo-leads.store'))->toBeTrue()->and(Route::has('fastleo-leads.api'))->toBeTrue();

        config(['fastleo.leads.form' => false]);
        $boot();
        expect(Route::has('fastleo-leads.store'))->toBeFalse()->and(Route::has('fastleo-leads.api'))->toBeTrue();

        config(['fastleo.leads.form' => true, 'fastleo.leads.keys' => null, 'fastleo.leads.path' => 'zayavki']);
        $boot();
        expect(Route::has('fastleo-leads.store'))->toBeTrue()->and(Route::has('fastleo-leads.api'))->toBeFalse()
            ->and(Route::getRoutes()->getByName('fastleo-leads.store')?->uri())->toBe('zayavki');

        config(['fastleo.leads.resource' => null]);
        $boot();
        expect(Route::has('fastleo-leads.store'))->toBeFalse()->and(Route::has('fastleo-leads.api'))->toBeFalse();
    });

    it('does not add the keys section without the leads section', function (): void {
        config(['fastleo.leads.resource' => null]);
        Fastleo::resources()->flush();

        expect(Fastleo::resources()->all())->not->toHaveKey('leads')->not->toHaveKey('lead-api-keys');
    });
});
