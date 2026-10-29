<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Отметить «На главной» с подтверждением.
 */
final class MarkFeatured extends Action
{
    protected ?string $label = 'На главную';

    protected ?string $confirm = 'Показать на главной?';

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Model $record) => $record->update(['is_featured' => true]));

        return ActionResult::success('На главной: '.$records->count());
    }
}
