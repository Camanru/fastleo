<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Тема с глобальным скоупом модели, как у сайта с несколькими арендаторами: темы «Чужая тема» не видны нигде.
 *
 * @property int $id
 * @property string $title
 */
#[Table('topics')]
#[Fillable(['title'])]
final class TenantTopic extends Model
{
    /**
     * Скоуп «только свой арендатор».
     */
    protected static function booted(): void
    {
        self::addGlobalScope('tenant', fn (Builder $query): Builder => $query->where('title', '!=', 'Чужая тема'));
    }
}
