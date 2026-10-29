<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Fastleo\Fastleo;
use Fastleo\Support\RecordWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенное массовое действие корзины «Восстановить». Право — `restore`.
 */
final class RestoreRecords extends DeletesRecords
{
    protected ?string $key = 'restore';

    protected string $icon = 'rotate-ccw';

    protected string $ability = 'restore';

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::resources.restore', locale: Fastleo::LOCALE);
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
        return $this->eachRecord($records, function (Model $record): bool {
            if (! method_exists($record, 'restore') || ! $record->restore()) {
                return false;
            }

            RecordWriter::moveToEnd($this->resource(), $record);

            return true;
        }, 'fastleo::actions.restored', false);
    }
}
