<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Notifications\NotificationBotResource;
use Fastleo\Support\Assets;
use Fastleo\Support\SchedulerPulse;
use Fastleo\Tests\Fixtures\Registry\TakenBuiltIn\SiteBotsResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Публичная папка и кеш — во временной папке: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-doctor-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/public/storage');
    File::ensureDirectoryExists($this->sandbox.'/bootstrap/cache');
    File::copyDirectory(Assets::distPath(), $this->sandbox.'/public/vendor/fastleo');
    $this->app->usePublicPath($this->sandbox.'/public');
    $this->app->useBootstrapPath($this->sandbox.'/bootstrap');
    config(['queue.default' => 'database']);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

/**
 * Проблемы из `fastleo:doctor --json` с кодом выхода.
 *
 * @return array{code: int, ok: bool, problems: list<array{level: string, scope: string, message: string, hint: ?string}>}
 */
function diagnosed(): array
{
    $code = Artisan::call('fastleo:doctor --json');
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return ['code' => $code, 'ok' => $result['ok'], 'problems' => $result['problems']];
}

/**
 * Сообщения проблем одного участка.
 *
 * @return list<string>
 */
function messages(string $scope, string $level): array
{
    return array_values(array_map(
        fn (array $problem): string => $problem['message'],
        array_filter(diagnosed()['problems'], fn (array $problem): bool => $problem['scope'] === $scope && $problem['level'] === $level),
    ));
}

it('finds no problems on a properly set up site', function (): void {
    $this->artisan('fastleo:doctor')->expectsOutputToContain('Проблем не найдено.')->assertSuccessful();

    expect(diagnosed())->toMatchArray(['code' => 0, 'ok' => true, 'problems' => []]);
});

it('reports a field without a column, outside $fillable, without a cast and with a missing relation', function (): void {
    useFixtureResources('Doctor');

    $result = diagnosed();
    $errors = implode("\n", messages('bad-fields', 'error'));

    expect($result['code'])->toBe(1)
        ->and($errors)->toContain('Поле «ghost» пишет в колонку, которой нет в таблице articles')
        ->and($errors)->toContain('Поле «excerpt» не в $fillable модели')
        ->and($errors)->toContain('Поле «keywords» хранит массив')
        ->and($errors)->toContain('Поле «tags» хранит массив')
        ->and($errors)->toContain('Поле «status» (Enum) требует каст')
        ->and($errors)->toContain('Связь поля «category_id»')
        ->and($errors)->toContain('Колонка порядка «nope»');
});

it('warns about a NOT NULL column without required()', function (): void {
    useFixtureResources('Doctor');

    expect(implode("\n", messages('bad-fields', 'warning')))->toContain('Колонка «title» не допускает NULL');
});

it('reports a resource whose table does not exist', function (): void {
    useFixtureResources('Doctor');

    expect(implode("\n", messages('missing-table', 'error')))->toContain('Нет таблицы ghosts модели');
});

it('reports a settings page with a forbidden field', function (): void {
    useFixtureResources('Doctor');

    expect(implode("\n", messages('bad-settings', 'error')))->toContain('Раздел описан с ошибкой');
});

it('warns that a built-in section is missing because the site took its address', function (): void {
    useFixtureResources('Registry/TakenBuiltIn');
    config(['fastleo.notifications.bots' => NotificationBotResource::class]);
    Fastleo::resources()->flush();

    $result = diagnosed();

    expect($result['code'])->toBe(0)
        ->and(implode("\n", messages('site', 'warning')))->toContain('Адрес /notification-bots занят разделом сайта '.SiteBotsResource::class);
});

it('reports a registry that cannot be built', function (): void {
    useFixtureResources('Registry/Duplicate');

    $result = diagnosed();

    expect($result['code'])->toBe(1)
        ->and(implode("\n", messages('registry', 'error')))->toContain('Список разделов не собирается');
});

it('prints errors and warnings with hints and exits with 1 on errors', function (): void {
    useFixtureResources('Doctor');

    $this->artisan('fastleo:doctor')
        ->expectsOutputToContain('[bad-fields] Поле «ghost»')
        ->expectsOutputToContain('Ошибок:')
        ->assertFailed();
});

it('does not fail on warnings alone', function (): void {
    config(['queue.default' => 'sync']);

    $result = diagnosed();

    expect($result['code'])->toBe(0)
        ->and($result['problems'])->toHaveCount(1)
        ->and($result['problems'][0])->toMatchArray(['level' => 'warning', 'scope' => 'site']);
});

it('rejects a guard that is not session based', function (): void {
    config(['auth.guards.fastleo.driver' => 'token']);

    expect(implode("\n", messages('site', 'error')))->toContain('должен работать на драйвере session, сейчас: token');
});

it('rejects a user model that cannot enter the admin', function (): void {
    config(['auth.providers.users.model' => stdClass::class]);

    expect(implode("\n", messages('site', 'error')))->toContain('Модель пользователей guard «fastleo» не подходит');
});

it('asks to migrate when package tables are missing', function (): void {
    Schema::drop('fastleo_imports');

    expect(implode("\n", messages('site', 'error')))->toContain('Нет таблицы fastleo_imports');
});

it('asks to migrate when the admin flag column is missing', function (): void {
    Schema::table('users', function ($table): void {
        $table->dropIndex(['fastleo_admin']);
        $table->dropColumn('fastleo_admin');
    });

    expect(implode("\n", messages('site', 'error')))->toContain('нет колонки fastleo_admin');
});

it('reports unpublished and outdated assets', function (): void {
    File::delete($this->sandbox.'/public/vendor/fastleo/fastleo.css');
    file_put_contents($this->sandbox.'/public/vendor/fastleo/fastleo.js', 'old');

    expect(implode("\n", messages('site', 'error')))->toContain('Ассеты админки не опубликованы')->toContain('fastleo.css')
        ->and(implode("\n", messages('site', 'warning')))->toContain('Ассеты админки устарели')->toContain('fastleo.js');
});

it('warns when the storage link is missing', function (): void {
    File::deleteDirectory($this->sandbox.'/public/storage');

    expect(implode("\n", messages('site', 'warning')))->toContain('Нет ссылки public/storage');
});

it('warns about a stale resource cache and accepts a fresh one', function (): void {
    file_put_contents(Fastleo::resources()->cachePath(), "<?php return ['Gone\\\\Resource'];");

    expect(implode("\n", messages('site', 'warning')))->toContain('Кеш списка разделов');

    Artisan::call('fastleo:cache');

    expect(messages('site', 'warning'))->toBe([]);
});

it('compares column length with maxLength where the database reports it', function (): void {
    useFixtureResources('Doctor');
    Schema::table('articles', fn ($table) => $table->string('title', 50)->change());

    $warnings = implode("\n", messages('bad-fields', 'warning'));

    expect($warnings)->toContain('допускает 255 символов, а колонка в базе — 50');
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite не сообщает длину строки');

describe('columns against the rules of the fields', function (): void {
    beforeEach(function (): void {
        createLimitsTable();
        useFixtureResources('DoctorLimits');
    });

    it('warns when the text of a field is longer than the column or is not limited at all', function (): void {
        $warnings = implode("\n", messages('limited', 'warning'));

        expect($warnings)->toContain('Поле «code» допускает 100 символов, а колонка в базе — 20')
            ->and($warnings)->toContain('Поле «note» не ограничивает длину текста, а колонка в базе вмещает только 20 символов');
    });

    it('does not compare the length of a field that is never written', function (): void {
        expect(implode("\n", messages('limited', 'warning')))->toContain('Поле «code» допускает 100 символов')
            ->not->toContain('Поле «status»');
    })->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite не сообщает длину строки');

    it('warns when the bounds of a number do not fit the column', function (): void {
        expect(implode("\n", messages('limited', 'warning')))->toContain('Поле «amount» допускает числа до 1000, а колонка в базе вмещает только 999.99');
    });

    it('warns when the bounds of an integer do not fit its column', function (): void {
        expect(implode("\n", messages('limited', 'warning')))->toContain('Поле «small» допускает числа до 40000, а колонка в базе вмещает только 32767');
    })->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite', 'SQLite хранит любые целые числа');

    it('warns when the options of a select are not in the enum column', function (): void {
        expect(implode("\n", messages('limited', 'warning')))->toContain('Варианты поля «kind» (z) не входят в enum-колонку базы');
    })->skip(fn (): bool => ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'enum-колонка есть только в MySQL');

    it('warns about a slug without a source because nothing fills it in', function (): void {
        expect(implode("\n", messages('limited', 'warning')))->toContain('Колонка «slug» не допускает NULL');
    });

    it('warns when the nested blocks can send more fields than max_input_vars allows', function (): void {
        expect(implode("\n", messages('wide-articles', 'warning')))->toContain('Форма раздела может отправить до 2006 полей, а PHP принимает 1000');
    });

    it('counts every chosen value of a multiple list as a field of the form', function (): void {
        expect(implode("\n", messages('wide-select', 'warning')))->toContain('Форма раздела может отправить до 1106 полей, а PHP принимает 1000');
    });
});

describe('connection to SoftNetica', function (): void {
    it('says nothing while no token is entered', function (): void {
        expect(diagnosed()['problems'])->toBe([]);
    });

    it('is silent when the reports arrive', function (): void {
        connectSoftNeticaWith();

        expect(diagnosed()['problems'])->toBe([]);
    });

    it('warns when a token is entered but the reports do not arrive', function (): void {
        connectSoftNetica();

        expect(implode("\n", messages('site', 'warning')))->toContain('отчёты не доходят дольше 30 минут');
    });

    it('reports an address that is not allowed and missing cabinet hosts', function (): void {
        connectSoftNetica();
        config(['fastleo.softnetica.url' => 'http://evil.example/path', 'fastleo.softnetica.cabinet_hosts' => []]);

        expect(implode("\n", messages('site', 'error')))->toContain('Адрес SoftNetica')
            ->and(implode("\n", messages('site', 'warning')))->toContain('Не заданы хосты кабинета');
    });

    it('skips the checks when the function is switched off', function (): void {
        connectSoftNetica();
        config(['fastleo.softnetica.enabled' => false]);

        expect(diagnosed()['problems'])->toBe([]);
    });
});

it('warns on a production site when the scheduler has not run', function (): void {
    $this->app['env'] = 'production';
    expect(collect(diagnosed()['problems'])->firstWhere('message', 'Планировщик не запускался дольше 10 минут (или ни разу): задачи пакета и сайта не выполняются.'))->toMatchArray(['level' => 'warning', 'scope' => 'site']);

    SchedulerPulse::beat();
    expect(collect(diagnosed()['problems'])->pluck('message')->implode(' '))->not->toContain('Планировщик не запускался');

    $this->app['env'] = 'local';
    Cache::flush();
    expect(collect(diagnosed()['problems'])->pluck('message')->implode(' '))->not->toContain('Планировщик не запускался');
});

it('warns that a robots.txt file hides the route of the package', function (): void {
    $message = 'Включён robots.txt пакета (fastleo.robots), но в public лежит свой robots.txt: веб-сервер отдаёт файл, маршрут пакета не сработает.';
    File::put($this->sandbox.'/public/robots.txt', "User-agent: *\n");

    expect(collect(diagnosed()['problems'])->pluck('message'))->not->toContain($message);

    config(['fastleo.robots.enabled' => true]);
    expect(collect(diagnosed()['problems'])->firstWhere('message', $message))->toMatchArray(['level' => 'warning', 'scope' => 'site']);

    File::delete($this->sandbox.'/public/robots.txt');
    expect(collect(diagnosed()['problems'])->pluck('message'))->not->toContain($message);
});
