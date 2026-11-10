<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\UserLookup;
use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Запускает MCP-сервер панели для Claude: агент читает и правит записи разделов от имени пользователя
 * `--user` с его правами (Policy). Сервер общается по stdio и ждёт сообщений
 * клиента — запускать вручную в терминале не нужно, команду прописывают в `.mcp.json` сайта.
 *
 * ```bash
 * php artisan fastleo:mcp --user=admin@site.ru
 * php artisan fastleo:mcp --user=admin@site.ru --allow-delete   # агенту разрешено удалять
 * ```
 */
#[AsCommand(name: 'fastleo:mcp')]
#[Description('MCP-сервер панели для Claude (запускается клиентом, см. .mcp.json)')]
#[Signature('fastleo:mcp {--user= : email пользователя, от имени которого работает агент} {--allow-delete : разрешить агенту удалять записи}')]
final class McpCommand extends Command
{
    /**
     * Проверить пользователя и запустить сервер.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $email = $this->option('user');
        $user = is_string($email) && $email !== '' ? UserLookup::byEmail(Str::lower(trim($email))) : null;

        if (! $user instanceof Authenticatable || ! $user instanceof FastleoUser || ! $user->canAccessFastleo()) {
            $this->components->error(__('fastleo::mcp.user_unknown', ['email' => is_string($email) ? $email : '']));

            return self::FAILURE;
        }

        Fastleo::guard()->setUser($user);

        if ($this->option('allow-delete') === true) {
            config(['fastleo.mcp.allow_delete' => true]);
        }

        // Сервер читает аргументы вызова через контейнер — нужен провайдер laravel/mcp, даже если автопоиск пакетов у сайта выключен.
        app()->register(McpServiceProvider::class);
        $transport = resolve(StdioTransport::class);
        resolve(FastleoServer::class, ['transport' => $transport])->start();
        $transport->run();

        return self::SUCCESS;
    }
}
