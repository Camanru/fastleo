<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ActivityTenant;

use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Models\TenantTopic;

/**
 * Раздел тем, модель которых скрывает записи чужого арендатора глобальным скоупом.
 */
final class TenantTopicResource extends Resource
{
    protected static string $model = TenantTopic::class;

    protected static ?string $slug = 'topics';

    /**
     * Название темы.
     */
    public function fields(): array
    {
        return [Text::make('title', 'Название')->required()];
    }
}
