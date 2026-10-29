<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Messengers\ChatIds;
use Fastleo\Messengers\MatchesMessenger;
use Fastleo\Messengers\Messenger;
use Fastleo\Messengers\MessengerFailed;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Номер канала в форме канала: при сохранении бот проверяется в мессенджере (состоит в чате, в канале —
 * администратор с правами публиковать, править и удалять). Сохраняется постоянный номер (вместо @имени),
 * пустое название берётся из мессенджера.
 *
 * Проверка идёт, только когда меняются мессенджер, токен или номер либо канал включают: выключенный канал
 * и сохранение без изменений не обращаются к мессенджеру.
 */
final class ChannelChat extends ChatIds implements DataAwareRule, ValidationRule
{
    /**
     * Данные формы.
     *
     * @var array<array-key, mixed>
     */
    private array $data = [];

    /** Канал, который редактируется (null — новый). */
    private ?Model $record = null;

    /**
     * Результат проверки: постоянный номер и название.
     *
     * @var array{id: string, title: string}|null
     */
    private ?array $checked = null;

    #[Override]
    public function getRules(?Model $record): array
    {
        $this->record = $record;
        $rules = parent::getRules($record);
        $rules[$this->name] = [...$rules[$this->name], MatchesMessenger::chats(), Channel::keepsAfterDelivery($record, $this->name), $this];

        return $rules;
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
     * Проверить канал в мессенджере.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->checked = null;
        $chat = is_array($value) ? reset($value) : $value;
        $messenger = is_string($this->data['messenger'] ?? null) ? Messenger::tryFrom($this->data['messenger']) : null;
        $typed = is_string($this->data['token'] ?? null) ? trim($this->data['token']) : '';
        $channel = $this->record instanceof Channel && $this->record->exists ? $this->record : null;
        $token = $typed !== '' ? $typed : (string) $channel?->token;

        if (! is_string($chat) || ! $messenger instanceof Messenger || preg_match($messenger->chatPattern(), $chat) !== 1 || preg_match($messenger->tokenPattern(), $token) !== 1) {
            return;
        }

        if (! in_array($this->data['enabled'] ?? '1', ['1', 1, true, 'on'], true)) {
            return;
        }

        if ($channel instanceof Channel && $channel->enabled && $typed === '' && $channel->messenger === $messenger && $channel->chat_id === $chat) {
            return;
        }

        try {
            $this->checked = $messenger->bot($token)->checkChannel($chat);
        } catch (MessengerFailed $exception) {
            $fail(__('fastleo::messengers.channel_failed', ['reason' => $exception->getMessage()], Fastleo::LOCALE));
        }
    }

    /**
     * Постоянный номер канала и название из мессенджера (если своё не задано).
     */
    #[Override]
    public function beforeSave(Model $record): void
    {
        if ($this->checked !== null) {
            $record->setAttribute($this->name, $this->checked['id']);
        }

        $name = $record->getAttribute('name');

        if (! is_string($name) || trim($name) === '') {
            $previous = $record->getOriginal('name');
            $record->setAttribute('name', $this->checked['title'] ?? (is_string($previous) && $previous !== '' ? $previous : $record->getAttribute($this->name)));
        }
    }
}
