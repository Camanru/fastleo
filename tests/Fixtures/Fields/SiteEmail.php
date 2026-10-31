<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Fields;

use Fastleo\Fields\Email;

/**
 * Наследник Email без своего кода: шаблон ищется по имени класса.
 */
final class SiteEmail extends Email {}
