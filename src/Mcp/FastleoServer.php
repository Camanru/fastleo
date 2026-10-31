<?php

declare(strict_types=1);

namespace Fastleo\Mcp;

use Fastleo\Mcp\Tools\CreateRecord;
use Fastleo\Mcp\Tools\DeleteRecord;
use Fastleo\Mcp\Tools\DescribeResource;
use Fastleo\Mcp\Tools\GetRecord;
use Fastleo\Mcp\Tools\ListRecords;
use Fastleo\Mcp\Tools\ListResources;
use Fastleo\Mcp\Tools\RunAction;
use Fastleo\Mcp\Tools\UpdateRecord;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * MCP-сервер админки: ИИ-агент читает и правит записи разделов от имени администратора, под которым
 * запущена команда `php artisan fastleo:mcp --user=admin@site.ru`. Права — те же, что в админке (Policy, `readonly`).
 *
 * Подключение в `.mcp.json` сайта:
 *
 * ```json
 * {"mcpServers": {"fastleo": {"command": "php", "args": ["artisan", "fastleo:mcp", "--user=admin@site.ru"]}}}
 * ```
 */
#[Name('Fastleo')]
#[Version('4.6.0')]
#[Instructions('Админка сайта. Начните с list-resources, затем describe-resource: он покажет поля, правила и права. Записи читают list-records и get-record, создают и меняют create-record и update-record (передавайте только нужные поля в values), действия запускает run-action. Удаление включается только при запуске сервера с --allow-delete.')]
final class FastleoServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListResources::class,
        DescribeResource::class,
        ListRecords::class,
        GetRecord::class,
        CreateRecord::class,
        UpdateRecord::class,
        DeleteRecord::class,
        RunAction::class,
    ];
}
