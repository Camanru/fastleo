<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Токен бота или номера чатов подходят мессенджеру, выбранному в поле `messenger` той же формы.
 *
 * ```php
 * Secret::make('token')->rules(fn (?Model $record) => [MatchesMessenger::token($record)]);
 * ChatIds::make('recipients')->rules([MatchesMessenger::chats()]);
 * ```
 */
final class MatchesMessenger implements DataAwareRule, ValidationRule
{
    /** @var array<array-key, mixed> */
    private array $data = [];

    /** Правило токена проверяется и для пустого поля (иначе смену мессенджера без нового токена не заметить). */
    public bool $implicit;

    private function __construct(private readonly bool $token, private readonly ?Model $record = null)
    {
        $this->implicit = $token;
    }

    /**
     * Правило для токена бота. Запись нужна, чтобы при смене мессенджера не оставить сохранённый токен
     * другого мессенджера: он ушёл бы чужому серверу.
     */
    public static function token(?Model $record = null): self
    {
        return new self(true, $record);
    }

    /**
     * Правило для номера чата или списка номеров.
     */
    public static function chats(): self
    {
        return new self(false);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Проверить значение. Мессенджер не выбран — проверяет правило поля `messenger`.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $messenger = $this->data['messenger'] ?? null;
        $messenger = is_string($messenger) ? Messenger::tryFrom($messenger) : null;

        if (! $messenger instanceof Messenger) {
            return;
        }

        if ($this->token) {
            $typed = is_string($value) ? trim($value) : '';

            if ($typed === '' && $this->record?->exists && $this->record->getAttribute('messenger') !== $messenger) {
                $fail(__('fastleo::messengers.token_required', ['messenger' => $messenger->label()], Fastleo::LOCALE));
            } elseif ($typed !== '' && preg_match($messenger->tokenPattern(), $typed) !== 1) {
                $fail(__('fastleo::messengers.token_invalid', ['messenger' => $messenger->label()], Fastleo::LOCALE));
            }

            return;
        }

        foreach (is_array($value) ? $value : [$value] as $chat) {
            if (is_string($chat) && $chat !== '' && preg_match($messenger->chatPattern(), trim($chat)) !== 1) {
                $fail(__('fastleo::messengers.chat_invalid', ['chat' => $chat, 'messenger' => $messenger->label()], Fastleo::LOCALE));

                return;
            }
        }
    }
}
