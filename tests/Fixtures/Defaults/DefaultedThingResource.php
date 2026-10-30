<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Defaults;

use Fastleo\Fields\DateTime;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DefaultedMood;
use Fastleo\Tests\Fixtures\Models\DefaultedThing;

/**
 * Поля без `default()` берут значения из модели и колонок базы, поле с `default()` — своё.
 */
final class DefaultedThingResource extends Resource
{
    protected static string $model = DefaultedThing::class;

    protected static ?string $slug = 'defaulted';

    public function fields(): array
    {
        return [
            Toggle::make('is_active'),
            Toggle::make('is_hidden')->default(true),
            Text::make('title'),
            Number::make('sort')->integer(),
            Text::make('kind'),
            Textarea::make('note'),
            DateTime::make('published_at'),
            Enum::make('mood')->enum(DefaultedMood::class),
            Text::make('legacy_at'),
        ];
    }
}
