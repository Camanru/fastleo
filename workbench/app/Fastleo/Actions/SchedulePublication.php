<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\Date;
use Fastleo\Fields\Toggle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Article;

/**
 * Демо: действие с мини-формой — назначить дату публикации выбранным статьям.
 */
final class SchedulePublication extends Action
{
    protected ?string $label = 'Назначить дату';

    protected string $icon = 'calendar';

    /**
     * Дата и галочка «сразу опубликовать».
     */
    public function fields(): array
    {
        return [
            Date::make('published_on', 'Дата публикации')->required(),
            Toggle::make('publish', 'Сразу опубликовать'),
        ];
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        foreach ($records as $article) {
            if ($article instanceof Article) {
                $article->update(['published_on' => $data['published_on'], 'is_published' => $data['publish'] === true || $article->is_published]);
            }
        }

        return ActionResult::success("Дата назначена: {$records->count()}");
    }
}
