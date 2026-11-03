<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorIcon;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Действие с иконкой, которой нет в наборе Lucide.
 */
final class BadIconAction extends Action
{
    protected string $icon = 'message-square-star';

    /**
     * Ничего не делает.
     *
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        return ActionResult::success('Готово');
    }
}
