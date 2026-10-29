<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Действие, которое ломается на уровне базы данных.
 */
final class BrokenAction extends Action
{
    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        DB::table('articles')->insert(['id' => $records->first()?->getKey(), 'title' => 'Дубль']);

        return ActionResult::success('Не дойдёт');
    }
}
