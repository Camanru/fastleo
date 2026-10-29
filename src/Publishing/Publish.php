<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Closure;
use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fastleo;
use Fastleo\Support\Authorization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;

/**
 * Кнопка «Опубликовать в каналы» у записи раздела сайта: замыкание собирает черновик поста (`Post`), пакет
 * создаёт черновик в разделе «Публикации» (картинки копируются в его папку) и открывает его — админ правит
 * текст и нажимает «Отправить». Сама кнопка ничего не отправляет.
 *
 * Кнопка видна, когда есть включённый канал, раздел «Публикации» включён и админ может создавать в нём записи
 * (Policy модели `Publication`, способность `create`) и видеть запись (`view`). Через MCP доступна: агент
 * получает черновик, но отправить его не может.
 *
 * ```php
 * public function actions(): array
 * {
 *     return [
 *         Publish::make(fn (News $news) => Post::make("**{$news->title}**\n\n{$news->lead}")
 *             ->images([$news->photo])
 *             ->button('Читать на сайте', route('news.show', $news))),
 *     ];
 * }
 * ```
 */
final class Publish extends Action
{
    protected ?string $key = 'fastleo-publish';

    protected string $icon = 'megaphone';

    protected string $ability = 'view';

    protected bool $bulk = false;

    /**
     * @param  Closure  $post  запись раздела → `Post`
     */
    public function __construct(private readonly Closure $post) {}

    /**
     * Действие с замыканием, которое собирает пост из записи.
     *
     * @param  Closure  $post  запись раздела → `Post`
     */
    public static function make(Closure $post): self
    {
        return new self($post);
    }

    /**
     * Создать черновик и открыть его.
     *
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException замыкание вернуло не `Post`
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $record = $records->first();
        $publications = PublicationResource::registered();

        if (! $record instanceof Model || ! $publications instanceof \Fastleo\Resource || ! Authorization::allows('create', $publications)) {
            return ActionResult::error(__('fastleo::publishing.publish_unavailable'));
        }

        $post = ($this->post)($record);

        if (! $post instanceof Post) {
            throw new LogicException(__('fastleo::exceptions.publish_post', ['resource' => $this->resource()::class], Fastleo::LOCALE));
        }

        $publication = DB::transaction(function () use ($post, $record): Publication {
            $publication = Publication::query()->create([
                'text' => $post->markdown(),
                'button_label' => $post->buttonLabel(),
                'button_url' => $post->buttonUrl(),
                'channel_ids' => array_map(strval(...), array_keys(Channel::options())),
                'source_resource' => $this->resource()::slug(),
                'source_id' => is_scalar($record->getKey()) ? (string) $record->getKey() : null,
                'user_id' => Fastleo::guard()->id() === null ? null : (string) Fastleo::guard()->id(),
            ]);
            $publication->update(['images' => $publication->copyImages($post)]);

            return $publication;
        });

        return ActionResult::redirect(route('fastleo.resources.edit', [$publications::slug(), $publication->id]), __('fastleo::publishing.draft_created'));
    }

    #[Override]
    public function getLabel(): string
    {
        return $this->label ?? __('fastleo::publishing.publish');
    }

    /**
     * Есть включённый канал, раздел «Публикации» включён и в нём можно создавать.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        $publications = PublicationResource::registered();

        return parent::allows($record) && Channel::anyEnabled() && $publications instanceof \Fastleo\Resource && Authorization::allows('create', $publications);
    }
}
