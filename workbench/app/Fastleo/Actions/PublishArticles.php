<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Actions;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Демо: опубликовать выбранные статьи (статус «Опубликовано» и галочка).
 */
final class PublishArticles extends Action
{
    protected ?string $label = 'Опубликовать';

    protected string $icon = 'send';

    protected ?string $confirm = 'Опубликовать статьи на сайте?';

    /**
     * Уже опубликованную статью «Опубликовать» не предлагает.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && (! $record instanceof Article || ! $record->is_published);
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Model $article): bool => $article instanceof Article && $article->update(['status' => ArticleStatus::Published, 'is_published' => true]));

        return ActionResult::success("Опубликовано: {$records->count()}");
    }
}
