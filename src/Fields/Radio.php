<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use LogicException;
use Override;

/**
 * Один вариант из нескольких кнопками-переключателями. Варианты — как у Select: массив, backed enum или замыкание.
 *
 * ```php
 * Radio::make('gender', 'Пол')->options(['m' => 'Мужской', 'f' => 'Женский'])->required();
 * ```
 */
class Radio extends Select
{
    /**
     * Radio выбирает только один вариант; для нескольких используйте Select::make()->multiple().
     *
     * @throws LogicException всегда при `true`
     */
    #[Override]
    public function multiple(bool $multiple = true): static
    {
        if ($multiple) {
            throw new LogicException(__('fastleo::exceptions.radio_multiple', ['field' => $this->name], Fastleo::LOCALE));
        }

        return $this;
    }

    /**
     * Radio не подгружает варианты по другому полю: шаблон этого не умеет.
     *
     * @throws LogicException всегда
     */
    #[Override]
    public function dependsOn(string $field): static
    {
        throw new LogicException(__('fastleo::exceptions.radio_depends', ['field' => $this->name], Fastleo::LOCALE));
    }
}
