<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Fields;

use Fastleo\Fields\RichText;

/**
 * Наследник RichText без своего кода: шаблон ищется по имени класса.
 */
final class SiteRichText extends RichText {}
