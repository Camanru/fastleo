<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\McpGuard;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Дописывает метку к заголовку.
 */
final class AppendMark extends Action
{
    protected ?string $label = 'Дописать метку';

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Model $record): bool => $record->update(['title' => $record->getAttribute('title').' ✔']));

        return ActionResult::success('Готово');
    }
}
