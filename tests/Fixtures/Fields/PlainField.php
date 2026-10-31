<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Fields;

use Fastleo\Fields\Field;

/**
 * Поле сайта без своего шаблона и без родителя с шаблоном: берёт шаблон по имени типа.
 */
final class PlainField extends Field {}
