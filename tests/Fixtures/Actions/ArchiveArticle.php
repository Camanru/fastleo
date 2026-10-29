<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Только для одной записи, своя способность Policy `archive`; ошибка — красным.
 */
final class ArchiveArticle extends Action
{
    protected bool $bulk = false;

    protected string $ability = 'archive';

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        return ActionResult::error('Архив недоступен');
    }
}
