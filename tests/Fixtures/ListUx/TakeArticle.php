<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ListUx;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Действие над одной статьёй: его кнопка стоит в строке списка как главная.
 */
final class TakeArticle extends Action
{
    protected ?string $label = 'Взять статью';

    protected bool $bulk = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        return ActionResult::success('Взято');
    }
}
