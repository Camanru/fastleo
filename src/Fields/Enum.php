<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use BackedEnum;
use Fastleo\Fastleo;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Список вариантов из PHP backed enum. Подпись варианта — метод `label()` enum (иначе имя случая).
 * В модели колонке нужен каст на этот enum. Без `enum()` поле — ошибка описания.
 *
 * ```php
 * Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required();
 * ```
 */
class Enum extends Select
{
    /**
     * Класс enum.
     *
     * @var class-string<BackedEnum>|null
     */
    protected ?string $enumClass = null;

    /**
     * Enum, из которого берутся варианты.
     *
     * ```php
     * Enum::make('status')->enum(ArticleStatus::class);
     * ```
     *
     * @param  class-string<BackedEnum>  $enum
     *
     * @throws InvalidArgumentException класс не backed enum
     */
    public function enum(string $enum): static
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException(__('fastleo::exceptions.enum_class', ['field' => $this->name, 'class' => $enum], Fastleo::LOCALE));
        }

        $this->enumClass = $enum;

        return $this->options($enum);
    }

    /**
     * Класс enum поля.
     *
     * @return class-string<BackedEnum>
     *
     * @throws LogicException enum не указан
     */
    public function getEnum(): string
    {
        if ($this->enumClass === null) {
            throw new LogicException(__('fastleo::exceptions.enum_missing', ['field' => $this->name], Fastleo::LOCALE));
        }

        return $this->enumClass;
    }

    /**
     * Правило — `in` по значениям enum (как у Select): объект `Rule::enum` не видит русских текстов ошибок пакета.
     */
    #[Override]
    protected function typeRules(): array
    {
        $this->getEnum();

        return parent::typeRules();
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.select';
    }
}
