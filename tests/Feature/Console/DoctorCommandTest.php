<?php

declare(strict_types=1);

use Fastleo\Closing\ClosingSettings;
use Fastleo\Fastleo;
use Fastleo\Notifications\NotificationBotResource;
use Fastleo\SoftNetica\SoftNeticaSettings;
use Fastleo\Support\Assets;
use Fastleo\Support\Doctor;
use Fastleo\Support\SchedulerPulse;
use Fastleo\Tests\Fixtures\Fields\StrictRichText;
use Fastleo\Tests\Fixtures\Registry\TakenBuiltIn\SiteBotsResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    // Публичная папка и кеш — во временной папке: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-doctor-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/public/storage');
    File::ensureDirectoryExists($this->sandbox.'/bootstrap/cache');
    File::copyDirectory(Assets::distPath(), $this->sandbox.'/public/vendor/fastleo');
    $this->app->usePublicPath($this->sandbox.'/public');
    $this->app->useBootstrapPath($this->sandbox.'/bootstrap');
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 4000]);
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

it('does not warn about a NOT NULL column when emptyValue() says what to write instead of null', function (): void {
    useFixtureResources('DoctorEmptyValue');

    expect(messages('empty-title', 'warning'))->toBe([]);
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

describe('password reset table', function (): void {
    it('reports a missing table of the reset broker', function (): void {
        config(['auth.passwords.fastleo.table' => 'admin_password_resets']);

        expect(diagnosed()['problems'])->toContain([
            'level' => 'error',
            'scope' => 'site',
            'message' => 'Нет таблицы admin_password_resets для ссылок сброса пароля (брокер auth.passwords.fastleo): «Забыли пароль?» в админке упадёт с ошибкой.',
            'hint' => 'Создайте таблицу миграцией сайта (колонки email, token, created_at) — пример в docs/auth.md.',
        ]);
    });

    it('does not offer to rename password_resets while the site resets its own passwords there', function (): void {
        Schema::create('password_resets', function ($table): void {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        config(['auth.passwords.fastleo.table' => 'admin_password_resets', 'auth.passwords.users.table' => 'password_resets']);

        $hint = (string) collect(diagnosed()['problems'])->firstWhere('message', 'Нет таблицы admin_password_resets для ссылок сброса пароля (брокер auth.passwords.fastleo): «Забыли пароль?» в админке упадёт с ошибкой.')['hint'];

        expect($hint)->toContain('не переименовывайте')->toContain("'table' => 'password_resets'");

        config(['auth.passwords.users.table' => 'password_reset_tokens']);
        $hint = (string) collect(diagnosed()['problems'])->firstWhere('message', 'Нет таблицы admin_password_resets для ссылок сброса пароля (брокер auth.passwords.fastleo): «Забыли пароль?» в админке упадёт с ошибкой.')['hint'];

        expect($hint)->toContain("Schema::rename('password_resets', 'admin_password_resets')");
    })->skip(fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'CREATE TABLE в MySQL неявно закрывает транзакцию теста');

    it('skips a broker that keeps tokens in the cache and reports a broker without a table', function (): void {
        config(['auth.passwords.fastleo' => ['driver' => 'cache', 'provider' => 'users']]);

        expect(diagnosed()['ok'])->toBeTrue();

        config(['auth.passwords.fastleo' => ['provider' => 'users']]);

        expect(implode("\n", messages('site', 'error')))->toContain('не задана таблица (table)');
    });
});

describe('package migrations that migrate would fail on', function (): void {
    it('reports an admin column whose package migration is not recorded', function (): void {
        DB::table('migrations')->where('migration', '2026_10_28_000000_add_fastleo_admin_to_users_table')->delete();

        expect(implode("\n", messages('site', 'error')))
            ->toContain('Миграция пакета 2026_10_28_000000_add_fastleo_admin_to_users_table не записана в таблице migrations, а то, что она создаёт, уже есть (fastleo_admin)');
    });

    it('only warns while an earlier site migration may still clear the way', function (): void {
        File::ensureDirectoryExists($this->sandbox.'/migrations');
        file_put_contents($this->sandbox.'/migrations/2026_10_27_000000_prepare_fastleo_4.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
        $this->app->make('migrator')->path($this->sandbox.'/migrations');
        DB::table('migrations')->where('migration', '2026_10_30_000300_create_fastleo_settings_table')->delete();

        expect(messages('site', 'error'))->toBe([])
            ->and(implode("\n", messages('site', 'warning')))->toContain('Раньше неё выполнятся миграции сайта (2026_10_27_000000_prepare_fastleo_4)');
    });

    it('lists every migration of the package', function (): void {
        $files = array_map(fn (string $path): string => basename($path, '.php'), glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: []);

        expect(array_keys(Doctor::MIGRATIONS))->toEqualCanonicalizing($files);
    });
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

it('warns that the queue hands an import to a second worker while the first still runs it', function (): void {
    $message = 'Очередь выдаёт задачу повторно через 90 с (retry_after), а импорт из файла может идти до 3600 с';

    config(['queue.connections.database.retry_after' => 90]);
    expect(messages('site', 'warning')[0] ?? '')->toContain($message);

    config(['queue.connections.database.retry_after' => 3600]);
    expect(messages('site', 'warning')[0] ?? '')->toContain('через 3600 с');

    config(['queue.connections.database.retry_after' => 3601]);
    expect(messages('site', 'warning'))->toBe([]);

    config(['queue.default' => 'sync', 'queue.connections.database.retry_after' => 90]);
    expect(implode("\n", messages('site', 'warning')))->not->toContain('retry_after');
});

it('says when a relation list switches to the search on the server', function (): void {
    Category::factory()->count(3)->create();

    expect(implode("\n", messages('articles', 'warning')))->not->toContain('relations.preload');

    config(['fastleo.relations.preload' => 2]);

    expect(implode("\n", messages('articles', 'warning')))
        ->toContain('Поле «category_id»: связанных записей больше 2 (fastleo.relations.preload) — вместо списка вариантов форма и фильтр показывают поиск по серверу');
});

it('does not check the queue timeout on a site without imports', function (): void {
    useFixtureResources('Doctor');
    config(['queue.connections.database.retry_after' => 90]);

    expect(implode("\n", messages('site', 'warning')))->not->toContain('retry_after');
});

it('accepts a fresh resource cache when SoftNetica and site closing are on', function (): void {
    config(['fastleo.softnetica.enabled' => true, 'fastleo.closing.enabled' => true]);

    Artisan::call('fastleo:cache');

    expect(require Fastleo::resources()->cachePath())->not->toContain(ClosingSettings::class, SoftNeticaSettings::class)
        ->and(messages('site', 'warning'))->toBe([]);
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

    it('warns when a text field keeps the default limit but the column holds more', function (): void {
        $warnings = implode("\n", messages('limited', 'warning'));

        expect($warnings)->toContain('Поле «title» не пропускает текст длиннее 255 символов, а колонка в базе вмещает 500.')
            ->and($warnings)->not->toContain('Поле «code» не пропускает')
            ->and($warnings)->not->toContain('Поле «phone»');
    })->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite не сообщает длину строки');

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

describe('markup of the RichText field', function (): void {
    beforeEach(function (): void {
        Schema::create('doctor_rich_notes', function ($table): void {
            $table->id();
            $table->longText('body')->nullable();
        });
        DB::table('doctor_rich_notes')->insert([
            ['body' => '<div class="uk-h3" role="note" data-lpu="1"><p>Текст</p></div><iframe src="https://prodoctorov.ru/widget/1/"></iframe>'],
            ['body' => '<p>Чистый текст</p>'],
            ['body' => null],
        ]);
        useFixtureResources('DoctorRichText');
    });

    it('warns that saving an old record will remove tags, attributes and players the site did not allow', function (): void {
        $warnings = implode("\n", messages('rich-notes', 'warning'));

        expect($warnings)->toContain('Поле «body» (RichText): в 1 из 2 проверенных записей при сохранении пропадёт разметка')
            ->toContain('теги div')
            ->toContain('атрибуты class, role, data-lpu')
            ->toContain('iframe с адресов prodoctorov.ru');
    });

    it('points to the sanitize() method of a field that cleans HTML by its own rules', function (): void {
        $problems = collect(diagnosed()['problems']);

        expect($problems->firstWhere('scope', 'strict-rich-notes')['hint'])->toBe('Разметку очищает '.StrictRichText::class.'::sanitize(). Если разметка нужна, разрешите её там до первого сохранения старых записей; если нет — ничего не делайте.')
            ->and($problems->firstWhere('scope', 'rich-notes')['hint'])->toContain('fastleo.rich_text');
    });

    it('stays silent when the site allowed that markup', function (): void {
        config([
            'fastleo.rich_text.tags' => ['div'],
            'fastleo.rich_text.attributes' => ['class', 'role', 'data-lpu'],
            'fastleo.rich_text.iframe_hosts' => ['https://prodoctorov.ru/widget/'],
        ]);

        expect(messages('rich-notes', 'warning'))->toBe([]);
    });

    it('reports a rich_text setting that allows something dangerous', function (): void {
        config(['fastleo.rich_text.attributes' => ['onclick']]);

        expect(implode("\n", messages('site', 'error')))->toContain('Настройка fastleo.rich_text недопустима');
    });

    it('lists the records the cleaning will change, literally, with a piece of text before and after', function (): void {
        DB::table('doctor_rich_notes')->insert(['body' => '<p>Скидка &l до <img src="/a.jpg" alt="" decoding="async"></p>']);

        $code = Artisan::call('fastleo:doctor --sanitize-diff --json');
        $report = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['fields']);
        $notes = $report->firstWhere('resource', 'rich-notes');

        expect($code)->toBe(0)
            ->and($notes)->toMatchArray(['field' => 'body', 'checked' => 3, 'changed' => 2])
            ->and($notes['records'])->toHaveCount(2)
            ->and($notes['records'][0])->toMatchArray(['key' => '1', 'locale' => null])
            ->and($notes['records'][0]['before'])->toStartWith('<div class="uk-h3"')
            ->and($notes['records'][1]['key'])->toBe('4')
            ->and($notes['records'][1]['before'])->toContain('&l до')
            ->and($notes['records'][1]['after'])->toContain('&amp;l до');
    });

    it('prints the records of the diff for a person and stays silent about unchanged fields', function (): void {
        $this->artisan('fastleo:doctor --sanitize-diff')
            ->expectsOutputToContain('[rich-notes] поле «body»: при сохранении изменится 1 из 2 записей с текстом.')
            ->expectsOutputToContain('было:')
            ->expectsOutputToContain('станет:')
            ->assertSuccessful();

        config([
            'fastleo.rich_text.tags' => ['div'],
            'fastleo.rich_text.attributes' => ['class', 'role', 'data-lpu'],
            'fastleo.rich_text.iframe_hosts' => ['https://prodoctorov.ru/widget/'],
        ]);

        Artisan::call('fastleo:doctor --sanitize-diff --json');
        $resources = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['fields'])->pluck('resource');

        expect($resources)->not->toContain('rich-notes');
    });

    it('shows only the first records of a long list but counts them all', function (): void {
        DB::table('doctor_rich_notes')->insert(array_fill(0, 60, ['body' => '<div class="x">текст</div>']));

        Artisan::call('fastleo:doctor --sanitize-diff --json');
        $notes = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['fields'])->firstWhere('resource', 'rich-notes');

        expect($notes['changed'])->toBe(61)
            ->and($notes['records'])->toHaveCount(50);
    });

    it('fails with the reason when the rich_text setting is not allowed', function (): void {
        config(['fastleo.rich_text.attributes' => ['onclick']]);

        $this->artisan('fastleo:doctor --sanitize-diff')
            ->expectsOutputToContain('Настройка fastleo.rich_text недопустима')
            ->assertFailed();
    });
});

describe('quick toggle and SetToggle', function (): void {
    beforeEach(function (): void {
        useFixtureResources('QuickToggle');
    });

    it('warns about quickToggle() on a read-only field or on a field outside the edit form', function (): void {
        $text = 'Поле «is_published»: quickToggle() включён, но поле только для чтения или не показывается в форме редактирования (readonly, onlyInList, hideOnEdit) — переключатель в списке не появится.';

        expect(messages('frozen-articles', 'warning'))->toContain($text)
            ->and(messages('listonly-articles', 'warning'))->toContain($text)
            ->and(messages('articles', 'warning'))->toBe([]);
    });

    it('warns about a SetToggle action on a read-only field', function (): void {
        expect(messages('frozen-articles', 'warning'))->toContain('Действие set-is-published-on (SetToggle): поле «is_published» только для чтения (readonly) — действие будет пропускать все записи.')
            ->and(messages('articles', 'warning'))->toBe([]);
    });

    it('reports a SetToggle action aimed at a field that is not a checkbox', function (): void {
        expect(messages('broken-toggle', 'error'))->toContain('Действие set-title-on (SetToggle): в ресурсе нет поля-галочки «title» (Checkbox или Toggle) — запуск действия упадёт.')
            ->and(messages('articles', 'error'))->toBe([]);
    });
});
