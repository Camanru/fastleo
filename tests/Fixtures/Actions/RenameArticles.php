<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Действие с мини-формой: приписать текст к заголовку.
 */
final class RenameArticles extends Action
{
    protected ?string $label = 'Дописать к заголовку';

    /**
     * Обязательный текст.
     */
    public function fields(): array
    {
        return [Text::make('suffix', 'Окончание')->required()->maxLength(20)];
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $suffix = is_string($data['suffix'] ?? null) ? $data['suffix'] : '';
        $records->each(fn (Model $record) => $record->update(['title' => $record->getAttribute('title').' '.$suffix]));

        return ActionResult::redirect(route('fastleo.resources.index', 'articles'), 'Переименовано');
    }
}
