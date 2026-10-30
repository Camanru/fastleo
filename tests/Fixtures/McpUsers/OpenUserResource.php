<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpUsers;

use Fastleo\Resources\UserResource;

/**
 * Раздел «Администраторы», который сайт сам открыл ИИ-агенту (`$mcpPersonalData = true`) — для тестов MCP.
 */
final class OpenUserResource extends UserResource
{
    protected static bool $mcp = true;

    protected static bool $mcpPersonalData = true;
}
