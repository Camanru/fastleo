<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\InspectBroken;

use Fastleo\Fields\Text;
use Fastleo\Settings;
use Override;

/**
 * Страница настроек с ошибкой описания: два поля с одним именем.
 */
final class BrokenSettings extends Settings
{
    /**
     * Дубль имени `note` — схема формы не соберётся.
     */
    #[Override]
    public function fields(): array
    {
        return [Text::make('note'), Text::make('note')];
    }
}
