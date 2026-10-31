<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpActions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Действие только для людей: ИИ-агенту через MCP недоступно (`$mcp = false`).
 */
final class HideFromAgent extends Action
{
    protected bool $mcp = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        return ActionResult::success('Выполнено человеком');
    }
}
