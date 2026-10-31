<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Doctor;

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\DoctorArticle;
use Workbench\App\Enums\ArticleStatus;

/**
 * Раздел со всеми ошибками описания, которые находит doctor.
 */
final class BadFieldsResource extends Resource
{
    protected static string $model = DoctorArticle::class;

    protected static ?string $slug = 'bad-fields';

    protected static ?string $sortColumn = 'nope';

    /**
     * Поле без колонки, вне $fillable, без каста, со связью без метода.
     */
    public function fields(): array
    {
        return [
            Text::make('title'),
            Text::make('ghost'),
            Text::make('excerpt'),
            Text::make('author_email')->onlyInList(),
            Tags::make('keywords'),
            Select::make('tags')->multiple()->options(['a' => 'A']),
            Enum::make('status')->enum(ArticleStatus::class),
            BelongsTo::make('category_id'),
        ];
    }
}
