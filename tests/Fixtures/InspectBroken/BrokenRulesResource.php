<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\InspectBroken;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use RuntimeException;
use Workbench\App\Models\Category;

/**
 * Раздел, у которого правила одного поля падают при вызове: остальное описание всё равно собирается.
 */
final class BrokenRulesResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'broken-rules';

    /**
     * Исправное поле и поле с падающими правилами.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название'),
            Text::make('code', 'Код')->rules(fn (): array => throw new RuntimeException('Правила сломаны')),
        ];
    }
}
