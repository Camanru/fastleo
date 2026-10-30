<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Fields;

use Fastleo\Fields\RichText;
use Override;

/**
 * RichText со своей очисткой: оставляет только текст.
 */
final class StrictRichText extends RichText
{
    #[Override]
    public function sanitize(?string $html): ?string
    {
        $text = trim(strip_tags((string) $html));

        return $text === '' ? null : '<p>'.e($text).'</p>';
    }
}
