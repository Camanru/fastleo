<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Один файл из файлового менеджера: документ, прайс, презентация. В колонке (`string`) —
 * путь относительно диска, например `uploads/prices/prays-2026.pdf`.
 *
 * ```php
 * File::make('price', 'Прайс')->extensions(['pdf', 'xlsx'])->directory('prices');
 * ```
 */
class File extends FileField
{
    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.file';
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['string', $this->pathRule()];
    }

    /**
     * На просмотре — крупная миниатюра или ссылка на файл.
     */
    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        $path = self::path($value);

        return $path === null ? null : $this->preview($path, large: true);
    }

    /**
     * В списке — маленькая миниатюра или ссылка на файл.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        $path = self::path($record->getAttribute($this->name));

        return $path === null || $this->formatUsing instanceof \Closure ? $this->display($record) : $this->preview($path);
    }
}
