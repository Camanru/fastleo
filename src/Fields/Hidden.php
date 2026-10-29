<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Support\ValidationMessages;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Скрытое поле формы: значение отправляется, но не видно. В списке и на просмотре не показывается.
 *
 * Обычно — значение по умолчанию при создании:
 *
 * ```php
 * Hidden::make('type')->default('news');
 * ```
 */
class Hidden extends Field
{
    protected bool $showInList = false;

    /**
     * Значение скрытого поля — не массив: подделанный запрос (`type[x]=y`) не должен записать в колонку список.
     * Остальные правила (`in:…`, `max:…`) задаёт сайт через `rules()`.
     */
    #[Override]
    protected function typeRules(): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value)) {
                $fail(ValidationMessages::line('any_of'));
            }
        }];
    }

    /**
     * Скрытое значение не показывается и на странице просмотра.
     */
    #[Override]
    public function isVisibleOnShow(Model $record): bool
    {
        return false;
    }
}
