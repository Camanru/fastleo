<?php

declare(strict_types=1);

use Fastleo\Auth\TwoFactor;
use Fastleo\Fastleo;
use Fastleo\Notifications\NotificationBot;
use Fastleo\Publishing\Channel;
use Fastleo\SoftNetica\Synchronizer;
use Fastleo\SoftNetica\SyncResult;
use Fastleo\SoftNetica\SyncState;
use Fastleo\Support\SettingsStore;
use Fastleo\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PragmaRX\Google2FA\Google2FA;
use Workbench\App\Models\User;

// Плагин браузерных тестов перед запуском делает @rmdir(tests/Browser/Traces); без каталога PHPUnit
// считает подавленное предупреждение и завершается кодом 1 при зелёных тестах.
if (! is_dir(__DIR__.'/Browser/Traces')) {
    mkdir(__DIR__.'/Browser/Traces', 0o755, true);
}

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Browser');
pest()->extend(TestCase::class)->group('performance')->in('Performance');

/**
 * Строгое сравнение JSON-значения из базы без учёта порядка ключей в объектах.
 * MySQL хранит ключи JSON-объекта отсортированными, SQLite и PostgreSQL (`json`) — как записаны;
 * порядок элементов списков сохраняют все базы, и он сравнивается.
 *
 * expect($page->faq)->toBeStoredJson([['question' => 'Первый', 'open' => true]]);
 */
expect()->extend('toBeStoredJson', function (array $expected): object {
    expect(sortedJsonKeys($this->value))->toBe(sortedJsonKeys($expected));

    return $this;
});

/**
 * Администратор fastleo с паролем «password».
 *
 * @param  array<string, mixed>  $attributes
 */
function admin(array $attributes = []): User
{
    return User::factory()->admin()->create($attributes);
}

/**
 * Код приложения-аутентификатора для секрета; $step — сдвиг в 30-секундных шагах от текущего.
 */
function totp(string $secret, int $step = 0): string
{
    $google2fa = new Google2FA;

    return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $step);
}

/**
 * Включённая двухфакторная защита у администратора (последний принятый код — шаг назад).
 *
 * @return array{secret: string, codes: list<string>}
 */
function withTwoFactor(User $admin): array
{
    $twoFactor = resolve(TwoFactor::class);
    $secret = $twoFactor->begin($admin);
    $codes = $twoFactor->confirm($admin, totp($secret, -1));

    return ['secret' => $secret, 'codes' => $codes ?? []];
}

/**
 * Отправка формы входа.
 *
 * @param  array<string, mixed>  $data
 */
function login(array $data): TestResponse
{
    return test()->post(route('fastleo.login.store'), $data);
}

/**
 * Взять ресурсы из папки tests/Fixtures/{папка} вместо демо-ресурсов workbench (встроенные разделы «Администраторы», «Уведомления» и страницы SoftNetica выключены).
 */
function useFixtureResources(string $folder): void
{
    config([
        'fastleo.resources.path' => __DIR__.'/Fixtures/'.$folder,
        'fastleo.resources.namespace' => 'Fastleo\\Tests\\Fixtures\\'.str_replace('/', '\\', $folder),
        'fastleo.users.resource' => null,
        'fastleo.notifications.bots' => null,
        'fastleo.publishing.channels' => null,
        'fastleo.publishing.publications' => null,
        'fastleo.softnetica.enabled' => false,
    ]);
    Fastleo::resources()->flush();
}

/**
 * Таблица `doctor_limits` с колонками ограниченной длины и диапазона (модель `LimitedThing`); на MySQL — ещё колонка enum.
 */
function createLimitsTable(): void
{
    $kind = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? "enum('a', 'b')" : 'varchar(10)';

    DB::statement("create table doctor_limits (id integer primary key, code varchar(20) not null, note varchar(20) not null, amount decimal(5,2) not null, small smallint not null, slug varchar(50) not null, kind {$kind} not null, status varchar(20) null)");
}

/**
 * Таблица заметок с секретным полем `pin` (модель `SecretNote`).
 */
function createSecretNotesTable(): void
{
    Schema::create('secret_notes', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('pin')->nullable();
        $table->timestamps();
    });
}

/**
 * Таблица узлов дерева со строковыми ключами и корзиной (модель `TreeNode`); ключи сравниваются без учёта регистра, как в MySQL.
 */
function createTreeNodesTable(): void
{
    $nocase = DB::connection()->getDriverName() === 'sqlite';

    Schema::create('tree_nodes', function (Blueprint $table) use ($nocase): void {
        $id = $table->string('id')->primary();
        $parent = $table->string('parent_id')->nullable();

        if ($nocase) {
            $id->collation('nocase');
            $parent->collation('nocase');
        }

        $table->string('title');
        $table->unsignedInteger('sort')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
}

/**
 * Войти в админку и открыть адрес (на форме с RichText — дождаться редактора).
 */
function signInAndVisit(string $url): mixed
{
    $page = visit(route('fastleo.login'))
        ->fill('email', 'admin@example.com')
        ->fill('password', 'password')
        ->press('Войти')
        ->navigate($url);

    // Пока запускается редактор RichText, клики теста могут теряться — ждём его готовности.
    if ($page->script("document.querySelector('[data-rich-text]') !== null") === true) {
        $page->assertPresent('[data-rich-text][data-ready]');
    }

    return $page;
}

/**
 * Минимальная форма статьи демо-раздела «Статьи».
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function articleData(array $overrides = []): array
{
    return ['type' => 'news', 'title' => 'Статья', 'status' => 'draft', 'views' => '0', ...$overrides];
}

/**
 * Строки выгруженного файла (значения ячеек).
 *
 * @return list<list<mixed>>
 */
function exportedRows(TestResponse $response, string $format = 'xlsx'): array
{
    $path = tempnam(sys_get_temp_dir(), 'fastleo-export');
    file_put_contents($path, $response->streamedContent());

    $reader = $format === 'csv' ? new CsvReader(new CsvOptions(FIELD_DELIMITER: ';')) : new XlsxReader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    File::delete($path);

    return $rows;
}

const TELEGRAM_TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

const MAX_TOKEN = 'max_token_0123456789abcdefABCDEF';

const SOFTNETICA_TOKEN = 'sn_0123456789abcdef0123456789abcdef0123456789abcdef';
const SOFTNETICA_OTHER_TOKEN = 'sn_fedcba9876543210fedcba9876543210fedcba9876543210';
const SOFTNETICA_URL = 'https://panel.softnetica.com/api/fastleo/sync';

/**
 * Ввести токен в настройках, как это делает форма.
 */
function connectSoftNetica(string $token = SOFTNETICA_TOKEN): void
{
    resolve(SettingsStore::class)->save(Fastleo::resources()->findSettings('softnetica'), ['token' => $token]);
}

/**
 * Ответ панели «принято» с данными клиента.
 *
 * @param  array<string, mixed>  $client
 */
function acceptedResponse(array $client = ['domain' => 'site.ru']): mixed
{
    return Http::response(['accepted' => true, 'client' => $client]);
}

/**
 * Синхронизировать с новым состоянием (как отдельный запуск команды).
 */
function syncNow(): SyncResult
{
    app()->forgetInstance(SyncState::class);
    app()->forgetInstance(Synchronizer::class);

    return resolve(Synchronizer::class)->run();
}

/**
 * Данные клиента, сохранённые для текущего токена.
 *
 * @return array<mixed>|null
 */
function storedClient(): ?array
{
    app()->forgetInstance(SyncState::class);

    return resolve(SyncState::class)->client();
}

/**
 * Ответ SoftNetica (поле `client`) со всеми блоками; `$override` заменяет блоки целиком.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function softneticaClient(array $override = []): array
{
    $nights = [];

    for ($i = 13; $i >= 0; $i--) {
        $nights[] = ['date' => now()->subDays($i)->toDateString(), 'copies' => ['main' => 'done', 'db' => $i === 0 ? 'partial' : 'done']];
    }

    $invoices = [];

    for ($i = 1; $i <= 14; $i++) {
        $invoices[] = ['id' => $i, 'act_available' => true, 'title' => 'Счёт № '.$i, 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'amount' => 12000, 'remaining_amount' => $i === 1 ? 12000 : 0,
            'due_at' => '2026-02-05', 'status' => 'paid', 'status_label' => $i === 1 ? 'Ожидает оплаты' : 'Оплачен', 'tone' => $i === 1 ? 'warn' : 'ok',
            'payments' => [['paid_at' => '2026-01-20T10:00:00+03:00', 'amount' => 12000, 'method_label' => 'Карта']], 'url' => 'https://my.softnetica.com/invoices/'.$i];
    }

    return array_replace([
        'domain' => 'site.ru',
        'status' => ['tone' => 'ok', 'label' => 'Работает'],
        'uptime_30d' => 99.95,
        'backup' => ['last_success_at' => now()->subHours(5)->toIso8601String(), 'tone' => 'ok'],
        'ssl' => ['expires_at' => now()->addDays(40)->toIso8601String(), 'days_left' => 40, 'tone' => 'ok'],
        'domain_expiry' => ['expires_on' => now()->addDays(20)->toDateString(), 'days_left' => 20, 'tone' => 'warn', 'paid_by_client' => true],
        'security' => ['problem' => false, 'checked_at' => now()->toIso8601String()],
        'backup_nights' => ['targets' => [['target' => 'main', 'label' => 'Основная копия'], ['target' => 'db', 'label' => 'Копия базы']], 'nights' => array_reverse($nights)],
        'restore' => ['automatic' => ['tested_on' => '2026-01-15', 'result' => 'Успешно', 'tone' => 'ok'], 'manual' => null],
        'billing' => [
            'summary' => ['next' => ['due_at' => '2026-02-05', 'amount' => 12000], 'overdue' => null],
            'invoices' => $invoices,
            'billing_url' => 'https://my.softnetica.com/billing',
        ],
        'reports' => [['month' => '2026-01', 'current' => false, 'url' => 'https://my.softnetica.com/reports/2026-01'], ['month' => '2026-02', 'current' => true, 'url' => 'https://my.softnetica.com/reports/2026-02']],
        'cabinet_url' => 'https://my.softnetica.com/',
    ], $override);
}

/**
 * Подключить сайт к SoftNetica и сохранить данные клиента, как после успешной синхронизации.
 *
 * @param  array<string, mixed>|null  $client
 */
function connectSoftNeticaWith(?array $client = null): void
{
    connectSoftNetica();
    resolve(SyncState::class)->recordConnected(SOFTNETICA_TOKEN, $client ?? softneticaClient());
}

/**
 * Бот раздела «Уведомления» (по умолчанию Telegram с одним чатом, включён).
 *
 * @param  array<string, mixed>  $attributes
 */
function notificationBot(array $attributes = []): NotificationBot
{
    return NotificationBot::query()->create([
        'name' => 'Бот',
        'messenger' => 'telegram',
        'token' => TELEGRAM_TOKEN,
        'recipients' => ['12345'],
        'enabled' => true,
        ...$attributes,
    ]);
}

/**
 * Канал публикаций (по умолчанию Telegram, включён).
 *
 * @param  array<string, mixed>  $attributes
 */
function publishingChannel(array $attributes = []): Channel
{
    return Channel::query()->create(['name' => 'Новости', 'messenger' => 'telegram', 'token' => TELEGRAM_TOKEN, 'chat_id' => '-1001234567890', 'enabled' => true, ...$attributes]);
}

/**
 * Значение с ключами объектов по алфавиту на всех уровнях; списки остаются в своём порядке.
 */
function sortedJsonKeys(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(sortedJsonKeys(...), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}
