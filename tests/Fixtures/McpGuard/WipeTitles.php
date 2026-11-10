<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpGuard;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Опасное действие с подтверждением в панели.
 */
final class WipeTitles extends Action
{
    protected ?string $label = 'Стереть заголовки';

    protected bool $danger = true;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Model $record): bool => $record->update(['title' => '—']));

        return ActionResult::success('Стёрто');
    }
}
