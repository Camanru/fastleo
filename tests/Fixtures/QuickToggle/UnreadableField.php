<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\QuickToggle;

use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Model;
use Override;
use RuntimeException;

/**
 * Поле сайта, значение которого не читается: `hydrate()` бросает исключение.
 */
final class UnreadableField extends Text
{
    /**
     * Значение не читается.
     */
    #[Override]
    public function hydrate(Model $record): never
    {
        throw new RuntimeException('Значение не читается.');
    }
}
