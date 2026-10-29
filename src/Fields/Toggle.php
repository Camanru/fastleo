<?php

declare(strict_types=1);

namespace Fastleo\Fields;

/**
 * Переключатель «вкл/выкл» — та же галочка в виде тумблера. Колонка `boolean`, каст `'boolean'`.
 *
 * ```php
 * Toggle::make('is_active', 'Показывать на сайте')->default(true);
 * ```
 */
class Toggle extends Checkbox {}
