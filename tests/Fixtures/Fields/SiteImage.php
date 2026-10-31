<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Fields;

use Fastleo\Fields\Image;

/**
 * Наследник Image без своего кода: шаблон ищется по имени класса.
 */
final class SiteImage extends Image {}
