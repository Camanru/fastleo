<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Workbench\App\Models\User;

beforeEach(function (): void {
    // Настоящий транспорт ждёт ввода из stdin вечно: подставляем тот, что сразу возвращается.
    $this->app->bind(StdioTransport::class, fn (): StdioTransport => new class extends StdioTransport
    {
        public function run(): void {}
    });
});

it('refuses to start without a known admin', function (?string $email): void {
    $this->artisan('fastleo:mcp', array_filter(['--user' => $email]))
        ->expectsOutputToContain('не найден или не имеет доступа в админку')
        ->assertFailed();

    expect(Fastleo::guard()->user())->toBeNull();
})->with([
    'no --user' => [null],
    'nobody with this email' => ['nobody@example.com'],
]);

it('refuses a user who has no access to the admin', function (): void {
    $plain = User::factory()->create();

    $this->artisan('fastleo:mcp', ['--user' => $plain->email])->assertFailed();

    expect(Fastleo::guard()->user())->toBeNull();
});

it('starts the server as the chosen admin with deletion off', function (): void {
    $admin = admin();

    $this->artisan('fastleo:mcp', ['--user' => $admin->email])->assertSuccessful();

    expect(Fastleo::guard()->user()?->getAuthIdentifier())->toBe($admin->getAuthIdentifier())
        ->and(config('fastleo.mcp.allow_delete'))->toBeFalse();
});

it('allows deletion only when asked with --allow-delete', function (): void {
    $this->artisan('fastleo:mcp', ['--user' => admin()->email, '--allow-delete' => true])->assertSuccessful();

    expect(config('fastleo.mcp.allow_delete'))->toBeTrue();
});
