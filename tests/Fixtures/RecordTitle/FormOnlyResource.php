<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\RecordTitle;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Workbench\App\Models\Category;

/**
 * Раздел, у которого все поля только в форме: в списке нет ни одного столбца, по которому можно назвать запись.
 */
final class FormOnlyResource extends Resource
{
    protected static string $model = Category::class;

    protected static ?string $slug = 'form-only';

    /**
     * Единственное поле скрыто из списка.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->hideInList()];
    }
}
