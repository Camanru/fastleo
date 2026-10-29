<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fields\Select;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Каналы публикации (включённые каналы раздела «Каналы»): выбираются только у черновика и после неудачной
 * отправки. На странице просмотра видны всегда.
 */
final class PublicationChannels extends Select
{
    #[Override]
    public function isVisibleInForm(?Model $record): bool
    {
        return parent::isVisibleInForm($record) && (! $record instanceof Publication || ! $record->exists || $record->isEditable());
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.select';
    }
}
