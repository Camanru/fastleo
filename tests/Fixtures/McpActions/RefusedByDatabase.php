<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpActions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Действие, на которое база данных отвечает отказом по связи (с целым кодом ошибки).
 */
final class RefusedByDatabase extends Action
{
    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        throw new QueryException('sqlite', 'update articles', [], new PDOException('FOREIGN KEY constraint failed'));
    }
}
