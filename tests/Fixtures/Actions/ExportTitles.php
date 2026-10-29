<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Скачать заголовки выбранных записей; право — просмотр.
 */
final class ExportTitles extends Action
{
    protected string $ability = 'view';

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $titles = $records->map(fn (Model $record): string => (string) $record->getAttribute('title'))->sort()->implode("\n");

        return ActionResult::download(response()->streamDownload(function () use ($titles): void {
            echo $titles;
        }, 'titles.txt'));
    }
}
