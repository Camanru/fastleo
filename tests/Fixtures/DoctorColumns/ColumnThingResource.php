<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorColumns;

use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\ColumnThing;
use RuntimeException;

/**
 * Поля шире своих колонок: длинный Textarea, число ниже нижней границы, варианты вне enum, целые за пределами типа, варианты и значение по умолчанию, которые не собираются.
 */
final class ColumnThingResource extends Resource
{
    protected static string $model = ColumnThing::class;

    protected static ?string $slug = 'column-things';

    public function fields(): array
    {
        return [
            Textarea::make('note')->maxLength(100)->required(),
            Number::make('lowest')->min(-5000)->required(),
            Select::make('kind')->options(['a' => 'A', 'z' => 'Z'])->required(),
            Number::make('tiny')->integer()->min(0)->max(40000)->required(),
            Number::make('tiny_unsigned')->integer()->min(0)->max(70000)->required(),
            Select::make('mode')->options(fn (): array => throw new RuntimeException('Варианты не собираются'))->required(),
            Text::make('stamp')->default(fn (): string => throw new RuntimeException('Значение не собирается')),
        ];
    }
}
