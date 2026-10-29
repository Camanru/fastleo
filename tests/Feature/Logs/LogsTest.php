<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Logs\LogReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/fastleo-logs-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    config(['logging.channels.single.path' => $this->dir.'/laravel.log']);
    $this->actingAs(admin(), Fastleo::GUARD);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * Запись журнала Laravel.
 */
function logLine(string $time, string $level, string $message, string $extra = ''): string
{
    return "[{$time}] testing.{$level}: {$message}\n".($extra === '' ? '' : $extra."\n");
}

describe('page', function (): void {
    it('shows the newest errors first and hides the rest by default', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-01 10:00:00', 'ERROR', 'Старая ошибка').logLine('2026-10-02 10:00:00', 'INFO', 'Просто info').logLine('2026-10-03 10:00:00', 'ERROR', 'Новая ошибка'));

        $html = $this->get(route('fastleo.logs.index'))->assertOk()->getContent();

        expect(strpos($html, 'Новая ошибка'))->toBeLessThan(strpos($html, 'Старая ошибка'))
            ->and($html)->not->toContain('Просто info');
    });

    it('filters by level and searches in the text', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-01 10:00:00', 'WARNING', 'Предупреждение про диск').logLine('2026-10-02 10:00:00', 'INFO', 'Информация про диск').logLine('2026-10-03 10:00:00', 'ERROR', 'Ошибка почты'));

        $this->get(route('fastleo.logs.index', ['level' => 'warnings']))->assertSee('Предупреждение про диск')->assertSee('Ошибка почты')->assertDontSee('Информация про диск');
        $this->get(route('fastleo.logs.index', ['level' => 'all']))->assertSee('Информация про диск');
        $this->get(route('fastleo.logs.index', ['level' => 'all', 'q' => 'ДИСК']))->assertSee('Информация про диск')->assertDontSee('Ошибка почты');
    });

    it('shows exception details apart from the message', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-03 10:00:00', 'ERROR', 'Не вышло {"exception":"[object] (RuntimeException(code: 0): boom)"}', "#0 /app/file.php(10): run()\n#1 {main}"));

        $this->get(route('fastleo.logs.index'))->assertSee('Не вышло')->assertSee('RuntimeException')->assertSee('#0 /app/file.php(10)');
    });

    it('pages back through a long journal from the byte where the previous page ended', function (): void {
        $content = '';
        foreach (range(1, 120) as $number) {
            $content .= logLine('2026-10-01 10:00:'.str_pad((string) ($number % 60), 2, '0', STR_PAD_LEFT), 'ERROR', 'Запись номер '.$number);
        }
        file_put_contents($this->dir.'/laravel.log', $content);

        $first = $this->get(route('fastleo.logs.index'))->assertSee('Запись номер 120')->assertSee('Запись номер 71')->assertDontSee('Запись номер 70<', false);
        preg_match('/before=(\d+)/', $first->getContent(), $match);

        $this->get(route('fastleo.logs.index', ['before' => $match[1]]))->assertSee('Запись номер 70')->assertSee('Запись номер 21')->assertDontSee('Запись номер 71');
    });

    it('scans a huge journal in bounded steps and offers to continue', function (): void {
        $handle = fopen($this->dir.'/laravel.log', 'wb');
        fwrite($handle, logLine('2026-10-01 10:00:00', 'ERROR', 'Самая старая'));
        for ($i = 0; $i < 260; $i++) {
            fwrite($handle, logLine('2026-10-02 10:00:00', 'DEBUG', str_repeat('x', 10000)));
        }
        fclose($handle);

        $this->get(route('fastleo.logs.index'))
            ->assertOk()
            ->assertSee('data-logs-older', false)
            ->assertSee('Просмотрено 2 МБ журнала');
    });

    it('ignores array parameters and a broken cursor', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-03 10:00:00', 'ERROR', 'Ошибка'));

        $this->get(route('fastleo.logs.index', ['q' => ['x'], 'level' => ['all'], 'before' => 'abc', 'file' => ['../x']]))->assertOk()->assertSee('Ошибка');
    });

    it('shows text safely and says when there are no records or files', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-03 10:00:00', 'ERROR', '<script>alert(1)</script>'));
        $this->get(route('fastleo.logs.index'))->assertDontSee('<script>alert(1)', false)->assertSee('&lt;script&gt;alert(1)', false);

        file_put_contents($this->dir.'/laravel.log', '');
        $this->get(route('fastleo.logs.index'))->assertSee('Записей нет.');

        File::delete($this->dir.'/laravel.log');
        $this->get(route('fastleo.logs.index'))->assertSee('Файлов журнала в папке нет.');
    });

    it('lets you choose among several journals and opens only files of the folder', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-03 10:00:00', 'ERROR', 'Из основного'));
        file_put_contents($this->dir.'/worker.log', logLine('2026-10-03 10:00:00', 'ERROR', 'Из воркера'));
        file_put_contents(dirname($this->dir).'/secret-'.basename($this->dir).'.txt', 'ТАЙНА');
        touch($this->dir.'/laravel.log', time() - 100);

        $this->get(route('fastleo.logs.index'))->assertSee('Из воркера')->assertSee('worker.log');
        $this->get(route('fastleo.logs.index', ['file' => 'laravel.log']))->assertSee('Из основного');
        $this->get(route('fastleo.logs.index', ['file' => '../secret-'.basename($this->dir).'.txt']))->assertDontSee('ТАЙНА')->assertSee('Из воркера');

        File::delete(dirname($this->dir).'/secret-'.basename($this->dir).'.txt');
    });
});

describe('clearing', function (): void {
    it('empties the journal but keeps the file and other files', function (): void {
        file_put_contents($this->dir.'/laravel.log', logLine('2026-10-03 10:00:00', 'ERROR', 'Ошибка'));
        file_put_contents($this->dir.'/worker.log', 'другой журнал');

        $this->post(route('fastleo.logs.clear'), ['file' => 'laravel.log'])
            ->assertRedirect(route('fastleo.logs.index', ['file' => 'laravel.log']))
            ->assertSessionHas('fastleo.success');

        expect(file_exists($this->dir.'/laravel.log'))->toBeTrue()
            ->and(filesize($this->dir.'/laravel.log'))->toBe(0)
            ->and(file_get_contents($this->dir.'/worker.log'))->toBe('другой журнал');
    });

    it('refuses files outside the journal folder', function (): void {
        $outside = dirname($this->dir).'/outside-'.basename($this->dir).'.log';
        file_put_contents($outside, 'не трогать');
        file_put_contents($this->dir.'/laravel.log', 'x');

        $this->post(route('fastleo.logs.clear'), ['file' => '../outside-'.basename($this->dir).'.log'])->assertNotFound();
        $this->post(route('fastleo.logs.clear'), ['file' => ['laravel.log']])->assertNotFound();
        $this->post(route('fastleo.logs.clear'), [])->assertNotFound();

        expect(file_get_contents($outside))->toBe('не трогать')->and(file_get_contents($this->dir.'/laravel.log'))->toBe('x');
        File::delete($outside);
    });

    it('does not clear by GET, without a csrf token or as a guest', function (): void {
        file_put_contents($this->dir.'/laravel.log', 'x');

        $this->get(route('fastleo.logs.clear'))->assertClientError();

        $this->app['env'] = 'production';
        $this->post(route('fastleo.logs.clear'), ['file' => 'laravel.log'])->assertStatus(419);
        $this->app['env'] = 'testing';

        $this->app['auth']->guard(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->post(route('fastleo.logs.clear'), ['file' => 'laravel.log'])->assertRedirect(route('fastleo.login'));

        expect(file_get_contents($this->dir.'/laravel.log'))->toBe('x');
    });
});

describe('access', function (): void {
    it('is closed when switched off and its menu item disappears', function (): void {
        config(['fastleo.logs.enabled' => false]);

        $this->get(route('fastleo.logs.index'))->assertForbidden();
        $this->post(route('fastleo.logs.clear'), ['file' => 'laravel.log'])->assertForbidden();
        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.logs.index'), false);
    });

    it('can be closed with the viewFastleoLogs gate', function (): void {
        Gate::define('viewFastleoLogs', fn (User $user): bool => false);

        $this->get(route('fastleo.logs.index'))->assertForbidden();
        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.logs.index'), false);

        Gate::define('viewFastleoLogs', fn (User $user): bool => true);
        $this->get(route('fastleo.logs.index'))->assertOk();
    });

    it('is listed in the menu', function (): void {
        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.logs.index'), false)->assertSee('Журнал ошибок');
    });
});

it('has a reader that returns nothing for an unreadable file', function (): void {
    expect(new LogReader($this->dir)->read($this->dir.'/missing.log', null, 10))->toBe(['entries' => [], 'next' => null, 'scanned' => 0])
        ->and(new LogReader($this->dir)->clear('missing.log'))->toBeNull()
        ->and(new LogReader($this->dir)->path('missing.log'))->toBeNull();
});
