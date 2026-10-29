<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\RecordKey;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Запись сайта, из которой сделана публикация (кнопка «Опубликовать в каналы»): ссылка на её страницу
 * в админке, если раздел есть и админ может её видеть. Только на странице просмотра.
 */
final class PublicationSource extends Field
{
    /** В списке не показывается. */
    protected bool $showInList = false;

    #[Override]
    public function isVisibleInForm(?Model $record): bool
    {
        return false;
    }

    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    #[Override]
    public function isExportable(): bool
    {
        return false;
    }

    #[Override]
    public function display(Model $record): ?Htmlable
    {
        if (! $record instanceof Publication || $record->source_resource === null || $record->source_id === null) {
            return null;
        }

        $resource = Fastleo::resources()->find($record->source_resource);
        $source = $resource instanceof Resource && RecordKey::valid($resource->newModel(), $record->source_id)
            ? $resource->newQuery()->whereKey($record->source_id)->first()
            : null;

        if (! $resource instanceof Resource || ! $source instanceof Model || ! Authorization::allows('view', $resource, $source)) {
            return null;
        }

        return new HtmlString('<a class="text-primary-text hover:underline" href="'.e(route('fastleo.resources.show', [$resource::slug(), $record->source_id])).'">'.e($resource::singular().' #'.$record->source_id).'</a>');
    }
}
