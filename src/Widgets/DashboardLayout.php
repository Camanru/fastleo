<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Раскладка дашборда одного администратора: порядок, размер и скрытие виджетов по их ключам.
 *
 * @property int $id
 * @property string $user_id
 * @property list<array{key: string, cols: int, rows: int, hidden: bool}> $layout
 */
#[Table('fastleo_dashboard_layouts')]
#[Unguarded]
final class DashboardLayout extends Model
{
    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['layout' => 'array'];
    }
}
