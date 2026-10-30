<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\DoctorLimits;

use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\LimitedThing;

/**
 * Поля, чьи правила шире колонок базы: длина строки, диапазон числа, варианты enum.
 */
final class LimitedResource extends Resource
{
    protected static string $model = LimitedThing::class;

    protected static ?string $slug = 'limited';

    /**
     * Текст длиннее колонки, текст без предела, число шире колонки, адрес без источника, поле только для чтения на короткой колонке.
     */
    public function fields(): array
    {
        return [
            Text::make('code')->maxLength(100)->required(),
            Textarea::make('note')->required(),
            Number::make('amount')->integer()->min(0)->max(1000)->required(),
            Number::make('small')->integer()->min(0)->max(40000)->required(),
            Slug::make('slug'),
            Select::make('kind')->options(['a' => 'A', 'z' => 'Z'])->required(),
            Text::make('status')->readonly(),
            Text::make('title'),
            Tel::make('phone'),
        ];
    }
}
