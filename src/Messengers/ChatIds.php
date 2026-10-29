<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use Fastleo\Fields\Tags;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Номера чатов бота метками и кнопка «Найти чаты бота»: она спрашивает у мессенджера чаты, где бот
 * недавно появлялся (поля `messenger` и токена — из той же формы), и даёт добавить их в список.
 *
 * Список хранится JSON-массивом (каст `array`); с `single()` — одна строка.
 *
 * ```php
 * ChatIds::make('recipients', 'Получатели');                       // несколько чатов
 * ChatIds::make('chat_id', 'Канал')->single()->tokenField('token'); // один чат
 * ```
 */
class ChatIds extends Tags
{
    /** Наибольшая длина номера (`-100…` или `@имя` канала). */
    protected int $maxTagLength = 33;

    /** Один чат строкой вместо списка. */
    private bool $single = false;

    /** Поле формы с токеном бота. */
    private string $tokenField = 'token';

    /** Поле формы с мессенджером. */
    private string $messengerField = 'messenger';

    /**
     * Хранить один чат строкой.
     */
    public function single(bool $single = true): static
    {
        $this->single = $single;

        return $this;
    }

    /**
     * Один чат строкой.
     */
    public function isSingle(): bool
    {
        return $this->single;
    }

    /**
     * Имя поля формы с токеном бота (по умолчанию `token`).
     */
    public function tokenField(string $name): static
    {
        $this->tokenField = $name;

        return $this;
    }

    /**
     * Имя поля формы с токеном бота.
     */
    public function getTokenField(): string
    {
        return $this->tokenField;
    }

    /**
     * Имя поля формы с мессенджером.
     */
    public function getMessengerField(): string
    {
        return $this->messengerField;
    }

    #[Override]
    public function hydrate(Model $record): array
    {
        $value = $record->getAttribute($this->name);

        if ($this->single) {
            return is_string($value) && $value !== '' ? [$value] : [];
        }

        return parent::hydrate($record);
    }

    /**
     * Список номеров; с `single()` — первый номер строкой (или null).
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        if ($this->single) {
            $first = is_array($value) ? reset($value) : null;
            $record->fill([$this->name => is_string($first) ? $first : null]);

            return;
        }

        parent::fill($record, $value);
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['array', 'max:'.($this->single ? 1 : 20)];
    }

    /**
     * Общий шаблон для наследников.
     */
    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.chat-ids';
    }

    #[Override]
    public function isExportable(): bool
    {
        return false;
    }
}
