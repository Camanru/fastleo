<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ActivityRecordHidden;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Topic;

/**
 * Темы, у которых название скрыто для записи «Секрет»: журнал не должен показывать его значения.
 */
final class TopicResource extends Resource
{
    protected static string $model = Topic::class;

    protected static bool $historyPanel = true;

    /**
     * Название темы; для записи «Секрет» скрыто.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()->hidden(fn (?Model $record): bool => $record?->getAttribute('title') === 'Секрет')];
    }
}
