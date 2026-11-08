<?php

declare(strict_types=1);

namespace Fastleo\Fields\Concerns;

/**
 * Ключи вложенных записей или связей, которые были в форме, когда её открыли (`HasMany`, `BelongsToMany`).
 *
 * Форма присылает остаток списка целиком, и всё, чего в нём нет, считалось удалённым. Если за время правки другой
 * администратор добавил строку или связь, устаревшая форма стёрла бы её. Форма запоминает ключи в скрытом поле
 * `{поле}__seen`; при сохранении убирается только то, что было в форме при открытии. Без поля (MCP, импорт,
 * быстрое добавление) действует прежнее правило: список заменяется целиком.
 */
trait TracksSeenKeys
{
    /**
     * Ключи, показанные в форме при открытии, или null, если форма их не прислала.
     *
     * @var list<string>|null
     */
    private ?array $seenKeys = null;

    /**
     * Имя скрытого поля формы с ключами, показанными при открытии.
     *
     * ```php
     * HasMany::make('prices')->seenInputName(); // 'prices__seen'
     * ```
     */
    public function seenInputName(): string
    {
        return $this->name.'__seen';
    }

    /**
     * Значение скрытого поля формы: присланное в прошлый раз (форма вернулась с ошибкой проверки — список, с которым её открыли,
     * остаётся прежним) или `$saved` (ключи, сохранённые сейчас). Подделанный массив (`links__seen[]=x`) присланным не считается.
     *
     * ```php
     * $field->seenInputValue('5,7'); // '5,7'
     * ```
     */
    public function seenInputValue(string $saved): string
    {
        $sent = old($this->seenInputName());

        return is_string($sent) ? $sent : $saved;
    }

    /**
     * Запомнить ключи из значения скрытого поля (через запятую); не строка — форма их не прислала.
     */
    public function rememberSeen(mixed $input): void
    {
        $this->seenKeys = is_string($input)
            ? array_values(array_unique(array_filter(explode(',', $input), fn (string $key): bool => $key !== '' && strlen($key) <= 64)))
            : null;
    }

    /**
     * Ключи, которые были в форме при открытии, или null — тогда считается, что форма показывала всё сохранённое.
     *
     * @return list<string>|null
     */
    public function seenKeys(): ?array
    {
        return $this->seenKeys;
    }

    /**
     * Правило скрытого поля: без него проверка отбросит значение.
     *
     * @return array<string, list<string>>
     */
    protected function seenRules(): array
    {
        return [$this->seenInputName() => ['nullable', 'string', 'max:65535']];
    }
}
