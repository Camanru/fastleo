<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Секрет: токен, ключ API. Ведёт себя как `Password` — форма всегда пустая, пустое поле при редактировании
 * оставляет прежнее значение, в списке, на просмотре, в экспорте, журнале изменений и MCP не показывается, —
 * но пишет в модель само значение, а не хеш. Шифрование — каст `encrypted` в модели.
 *
 * ```php
 * Secret::make('api_key', 'Ключ API')->required();
 * // в модели: protected function casts(): array { return ['api_key' => 'encrypted']; }
 * ```
 */
class Secret extends Password
{
    /** Наибольшая длина значения. */
    protected int $maxLength = 1000;

    #[Override]
    protected function typeRules(): array
    {
        return ['string', 'max:'.$this->maxLength];
    }

    /**
     * Пробелы по краям — случайные (вставка из буфера), убираются.
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : $value;
    }

    /**
     * Пустое значение не меняет секрет, иначе в модель пишется само значение.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        if (is_string($value)) {
            $record->fill([$this->name => $value]);
        }
    }
}
