<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Password;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Settings;
use Fastleo\Support\FormSchema;
use Fastleo\Support\SettingsStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    useFixtureResources('Settings');
    $this->actingAs(admin(['email' => 'anna@example.com']), Fastleo::GUARD);
});

/**
 * Форма страницы «site» с правками.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function siteForm(array $overrides = []): array
{
    return ['title' => 'Стоматология', 'email' => 'info@example.com', 'per_page' => '12', 'maintenance' => '0', ...$overrides];
}

describe('page', function (): void {
    it('is listed in the menu under the settings group and shows defaults before the first save', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.settings.edit', 'site'), false)->assertSee('Настройки');

        $this->get(route('fastleo.settings.edit', 'site'))
            ->assertOk()
            ->assertSee('Site')
            ->assertSee('value="Мой сайт"', false)
            ->assertSee('value="10"', false);
    });

    it('saves described fields only and shows the saved values', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm(['hacker' => 'x', 'fastleo_admin' => '1', 'keywords' => ['a', 'b']]))
            ->assertRedirect(route('fastleo.settings.edit', 'site'))
            ->assertSessionHas('fastleo.success', 'Настройки сохранены.');

        $stored = json_decode((string) DB::table('fastleo_settings')->where('slug', 'site')->value('value'), true);
        expect($stored)->toHaveKeys(['title', 'email', 'per_page', 'maintenance', 'keywords'])
            ->not->toHaveKeys(['hacker', 'fastleo_admin'])
            ->and($stored['title'])->toBe('Стоматология')
            ->and($stored['per_page'])->toBe(12)
            ->and($stored['maintenance'])->toBeFalse();

        $this->get(route('fastleo.settings.edit', 'site'))->assertSee('value="Стоматология"', false)->assertSee('value="12"', false);
    });

    it('validates the fields and keeps the old values on an error', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm());

        $this->put(route('fastleo.settings.update', 'site'), siteForm(['title' => '', 'email' => 'не почта']))
            ->assertSessionHasErrors(['title', 'email']);

        expect(fastleo_setting('site.title'))->toBe('Стоматология');
    });

    it('round-trips list fields: tags, repeater and key-value pairs', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm([
            'keywords' => ['врач', 'клиника'],
            'phones' => [['number' => '111'], ['number' => '222']],
            'meta' => [['key' => 'a', 'value' => '1'], ['key' => 'b', 'value' => '2']],
        ]))->assertSessionHasNoErrors();

        expect(fastleo_setting('site.keywords'))->toBe(['врач', 'клиника'])
            ->and(fastleo_setting('site.phones'))->toBe([['number' => '111'], ['number' => '222']])
            ->and(fastleo_setting('site.meta'))->toBe(['a' => '1', 'b' => '2']);

        $this->get(route('fastleo.settings.edit', 'site'))->assertOk()->assertSee('value="222"', false);
    });

    it('keeps the old value of a field hidden by visibleWhen', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm(['maintenance' => '1', 'other' => 'Сайт закрыт']));
        expect(fastleo_setting('site.other'))->toBe('Сайт закрыт');

        $this->put(route('fastleo.settings.update', 'site'), siteForm(['maintenance' => '0', 'other' => 'не должно записаться']));

        expect(fastleo_setting('site.other'))->toBe('Сайт закрыт');
    });

    it('rejects the page for unknown addresses and for guests', function (): void {
        $this->get(route('fastleo.settings.edit', 'nothing'))->assertNotFound();
        $this->put(route('fastleo.settings.update', 'nothing'), [])->assertNotFound();

        $this->app['auth']->guard(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->get(route('fastleo.settings.edit', 'site'))->assertRedirect(route('fastleo.login'));
        $this->put(route('fastleo.settings.update', 'site'), siteForm())->assertRedirect(route('fastleo.login'));
        expect(DB::table('fastleo_settings')->count())->toBe(0);
    });

    it('needs a csrf token', function (): void {
        $this->app['env'] = 'production';

        $this->put(route('fastleo.settings.update', 'site'), siteForm())->assertStatus(419);
        expect(DB::table('fastleo_settings')->count())->toBe(0);
    });

    it('hides a page from admins who may not open it, in the menu and by address', function (): void {
        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.settings.edit', 'owner'), false);
        $this->get(route('fastleo.settings.edit', 'owner'))->assertForbidden();
        $this->put(route('fastleo.settings.update', 'owner'), ['note' => 'x'])->assertForbidden();

        $this->actingAs(admin(['email' => 'owner@example.com']), Fastleo::GUARD);
        $this->flushSession();
        $this->get(route('fastleo.settings.edit', 'owner'))->assertOk();
        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.settings.edit', 'owner'), false);
    });
});

describe('secrets', function (): void {
    it('stores encrypted fields encrypted and gives them back decrypted', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm(['secret' => 'токен-12345']));

        $raw = (string) DB::table('fastleo_settings')->where('slug', 'site')->value('value');
        expect($raw)->not->toContain('токен')->not->toContain('12345')
            ->and(fastleo_setting('site.secret'))->toBe('токен-12345');

        $this->get(route('fastleo.settings.edit', 'site'))->assertOk()->assertDontSee('токен-12345');
    });

    it('keeps a saved secret when the form comes with an empty field and replaces it with a new one', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm(['secret' => 'старый-секрет']));

        $this->put(route('fastleo.settings.update', 'site'), siteForm(['secret' => '', 'title' => 'Новое название']))->assertRedirect();
        expect(fastleo_setting('site.secret'))->toBe('старый-секрет')
            ->and(fastleo_setting('site.title'))->toBe('Новое название');

        $this->put(route('fastleo.settings.update', 'site'), siteForm(['secret' => 'новый-секрет']))->assertRedirect();
        expect(fastleo_setting('site.secret'))->toBe('новый-секрет');
    });

    it('does not break when the key cannot be decrypted any more', function (): void {
        DB::table('fastleo_settings')->insert(['slug' => 'site', 'value' => json_encode(['secret' => 'not-encrypted', 'title' => 'Т']), 'created_at' => now(), 'updated_at' => now()]);
        resolve(SettingsStore::class)->flush();

        expect(fastleo_setting('site.secret', 'запасной'))->toBe('запасной')->and(fastleo_setting('site.title'))->toBe('Т');
    });

    it('leaves an empty secret empty', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm(['secret' => '']));

        expect(fastleo_setting('site.secret'))->toBeNull()
            ->and(Crypt::getKey())->not->toBeEmpty();
    });
});

describe('fastleo_setting()', function (): void {
    it('uses the default of the field until the page is saved', function (): void {
        expect(fastleo_setting('site.title'))->toBe('Мой сайт')
            ->and(fastleo_setting('site.per_page'))->toBe(10)
            ->and(fastleo_setting('site.email'))->toBeNull()
            ->and(fastleo_setting('site.email', 'запасная@example.com'))->toBe('запасная@example.com');
    });

    it('prefers the default of the field to the fallback value', function (): void {
        expect(fastleo_setting('site.title', 'запасное'))->toBe('Мой сайт')
            ->and(fastleo_setting('site.per_page', 99))->toBe(10)
            ->and(fastleo_setting('nothing.key', 'запасное'))->toBe('запасное');
    });

    it('prefers the saved value and falls back to the given default for an emptied field', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm(['title' => 'Своё', 'email' => '']));

        expect(fastleo_setting('site.title', 'запасное'))->toBe('Своё')
            ->and(fastleo_setting('site.email', 'info@site.ru'))->toBe('info@site.ru')
            ->and(fastleo_setting('site.email'))->toBeNull();
    });

    it('answers the default for unknown pages, unknown keys and broken keys', function (): void {
        expect(fastleo_setting('nothing.key', 'x'))->toBe('x')
            ->and(fastleo_setting('site.unknown', 'y'))->toBe('y')
            ->and(fastleo_setting('site', 'z'))->toBe('z')
            ->and(fastleo_setting('.key', 'w'))->toBe('w')
            ->and(fastleo_setting('', 'v'))->toBe('v')
            ->and(Fastleo::setting('site.title'))->toBe('Мой сайт');
    });

    it('reads all pages with one query per request and forgets the cache on save', function (): void {
        $this->put(route('fastleo.settings.update', 'site'), siteForm());
        resolve(SettingsStore::class)->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        fastleo_setting('site.title');
        fastleo_setting('site.email');
        fastleo_setting('owner.note');

        expect(DB::getQueryLog())->toHaveCount(1);

        $this->put(route('fastleo.settings.update', 'site'), siteForm(['title' => 'Новое']));
        expect(fastleo_setting('site.title'))->toBe('Новое');
    });
});

describe('description', function (): void {
    it('refuses fields that do not work on a settings page', function (Closure $field): void {
        $page = new class($field) extends Settings
        {
            public function __construct(private readonly Closure $field) {}

            public function fields(): array
            {
                return [($this->field)()];
            }
        };

        expect(fn (): FormSchema => $page->schema())->toThrow(LogicException::class);
    })->with([
        'relation' => [fn (): BelongsTo => BelongsTo::make('category_id')],
        'slug' => [fn (): Slug => Slug::make('slug')->from('title')],
        'password' => [fn (): Password => Password::make('password')],
        'translatable' => [fn (): Text => Text::make('title')->translatable()],
        'readonly' => [fn (): Text => Text::make('code')->readonly()],
        'hidden' => [fn (): Text => Text::make('code')->hidden()],
    ]);

    it('builds the label, slug and group from the class name', function (): void {
        $owner = Fastleo::resources()->findSettings('owner');

        expect($owner::label())->toBe('Owner')
            ->and($owner::slug())->toBe('owner')
            ->and($owner::icon())->toBe('settings')
            ->and($owner::position())->toBe(0)
            ->and($owner::group())->toBe('Настройки')
            ->and($owner->fieldNames())->toBe(['note']);
    });

    it('explains a repeated or invalid address', function (): void {
        config(['fastleo.resources.path' => __DIR__.'/../../Fixtures/SettingsDuplicate', 'fastleo.resources.namespace' => 'Fastleo\\Tests\\Fixtures\\SettingsDuplicate']);
        Fastleo::resources()->flush();

        expect(fn (): array => Fastleo::resources()->settings())->toThrow(LogicException::class, 'совпадает');
    });
});
