<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne as HasOneRelation;
use Override;

/**
 * Одна вложенная запись в форме родителя: карточка «Баннер», «Реквизиты».
 * Имя поля — метод связи `HasOne` модели. Запись создаётся, когда заполнено хотя бы одно поле, и не удаляется.
 *
 * ```php
 * HasOne::make('banner', 'Баннер')->fields([Text::make('title'), Url::make('link')]);
 * ```
 */
class HasOne extends Nested
{
    #[Override]
    public function isSingle(): bool
    {
        return true;
    }

    #[Override]
    protected function matchesRelation(mixed $relation): bool
    {
        return $relation instanceof HasOneRelation;
    }

    /**
     * Обновить вложенную запись или создать её, если что-то заполнено.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        $block = self::blocks($value)[0] ?? null;

        if ($block === null) {
            return;
        }

        $relation = $this->relationInstance($record);
        $child = $relation->first();

        if (! $child instanceof Model) {
            if ($this->isEmptyBlock($block['values'])) {
                return;
            }

            $child = $relation->make();
        }

        $this->saveChild($relation, $child, $block['values']);
        $record->unsetRelation($this->name);
    }
}
