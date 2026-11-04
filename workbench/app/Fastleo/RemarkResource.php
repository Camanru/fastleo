<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\MorphTo;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Remark;

/**
 * Раздел «Заметки»: заметка относится к статье или к товару, который виден на сайте (поле `MorphTo`).
 */
final class RemarkResource extends Resource
{
    protected static string $model = Remark::class;

    protected static ?string $label = 'Заметки';

    protected static ?string $singular = 'Заметка';

    protected static string $icon = 'sticky-note';

    protected static ?string $group = 'Каталог';

    protected static int $position = 30;

    /**
     * Текст заметки и объект, о котором она.
     */
    public function fields(): array
    {
        return [
            Textarea::make('body', 'Заметка')->required()->showInList(),
            MorphTo::make('subject', 'О чём')->types([
                ArticleResource::class,
                ProductResource::class => fn (Builder $query) => $query->where('is_visible', true),
            ]),
        ];
    }
}
