<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fields\Gallery;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Картинки публикации: меняются только у черновика и после неудачной отправки (у отправленного поста
 * Telegram не даёт пересобрать альбом). На странице просмотра видны всегда.
 */
final class PublicationImages extends Gallery
{
    #[Override]
    public function isVisibleInForm(?Model $record): bool
    {
        return parent::isVisibleInForm($record) && (! $record instanceof Publication || ! $record->exists || $record->isEditable());
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.gallery';
    }
}
