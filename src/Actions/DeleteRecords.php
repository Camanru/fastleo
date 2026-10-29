<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенное массовое действие «Удалить» (в корзину, если у модели SoftDeletes). Право — `delete`.
 */
final class DeleteRecords extends DeletesRecords
{
    protected ?string $key = 'delete';

    protected string $icon = 'trash-2';

    protected bool $danger = true;

    protected string $ability = 'delete';

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::resources.delete', locale: Fastleo::LOCALE);
    }

    #[Override]
    public function getConfirm(): string
    {
        return __($this->resource()::usesSoftDeletes() ? 'fastleo::actions.confirm_delete' : 'fastleo::actions.confirm_delete_forever', locale: Fastleo::LOCALE);
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        return $this->eachRecord($records, fn (Model $record): ?bool => $record->delete(), 'fastleo::actions.deleted', true);
    }
}
