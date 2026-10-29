<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенное массовое действие корзины «Удалить навсегда». Право — `forceDelete`.
 */
final class ForceDeleteRecords extends DeletesRecords
{
    protected ?string $key = 'force-delete';

    protected string $icon = 'trash-2';

    protected bool $danger = true;

    protected string $ability = 'forceDelete';

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::resources.force_delete', locale: Fastleo::LOCALE);
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::actions.confirm_delete_forever', locale: Fastleo::LOCALE);
    }

    #[Override]
    public function isForTrash(): bool
    {
        return true;
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        return $this->eachRecord($records, fn (Model $record): ?bool => $record->forceDelete(), 'fastleo::actions.deleted', true, true);
    }
}
