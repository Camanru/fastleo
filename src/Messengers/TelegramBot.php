<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Override;
use Throwable;

/**
 * Бот Telegram (Bot API). Токен входит в адрес запроса, поэтому тексты ошибок всегда очищаются.
 *
 * ```php
 * (new TelegramBot($token))->sendText('@my_channel', 'Привет');
 * ```
 */
final class TelegramBot extends Bot
{
    private const string URL = 'https://api.telegram.org/bot';

    /** Самая длинная подпись к фото. */
    public const int CAPTION_LIMIT = 1024;

    /** Самый длинный текст сообщения. */
    public const int MESSAGE_LIMIT = 4096;

    #[Override]
    public function messenger(): Messenger
    {
        return Messenger::Telegram;
    }

    #[Override]
    public function me(): array
    {
        $me = $this->call('getMe');
        $username = $me['username'] ?? null;

        return ['id' => $this->string($me['id'] ?? null), 'name' => is_string($username) ? '@'.$username : $this->string($me['first_name'] ?? null)];
    }

    /**
     * Чаты из последних событий бота (`getUpdates`). Не работает, если у бота настроен webhook.
     */
    #[Override]
    public function recentChats(): array
    {
        $updates = $this->call('getUpdates', ['limit' => 100, 'timeout' => 0, 'allowed_updates' => ['message', 'channel_post', 'my_chat_member']]);
        $chats = [];

        foreach ($updates as $update) {
            $chat = data_get($update, 'message.chat', data_get($update, 'channel_post.chat', data_get($update, 'my_chat_member.chat')));

            if (! is_array($chat) || ! isset($chat['id'])) {
                continue;
            }

            $id = $this->string($chat['id']);
            $name = trim($this->string($chat['first_name'] ?? null).' '.$this->string($chat['last_name'] ?? null));
            $title = $this->string($chat['title'] ?? null) ?: ($name ?: '@'.$this->string($chat['username'] ?? null));
            $chats[$id] = ['id' => $id, 'title' => $title, 'type' => $this->string($chat['type'] ?? null)];
        }

        return array_values($chats);
    }

    /**
     * `getChat` (постоянный числовой номер вместо @имени, название) и `getChatMember` бота: в канале — администратор
     * с `can_post_messages`, `can_edit_messages`, `can_delete_messages`; в группе — участник.
     */
    #[Override]
    public function checkChannel(string $chatId): array
    {
        $botId = $this->me()['id'];
        $chat = $this->call('getChat', ['chat_id' => $chatId]);
        $id = $this->string($chat['id'] ?? null);
        $member = $this->call('getChatMember', ['chat_id' => $id, 'user_id' => (int) $botId]);
        $status = $member['status'] ?? null;

        if (($chat['type'] ?? null) === 'channel') {
            if ($status !== 'administrator') {
                throw new MessengerFailed(__('fastleo::messengers.not_admin', [], Fastleo::LOCALE));
            }

            $missing = array_keys(array_filter(
                ['post' => 'can_post_messages', 'edit' => 'can_edit_messages', 'delete' => 'can_delete_messages'],
                fn (string $right): bool => ($member[$right] ?? false) !== true,
            ));

            if ($missing !== []) {
                throw $this->rightsMissing($missing);
            }
        } elseif (! in_array($status, ['administrator', 'member', 'creator'], true)) {
            throw new MessengerFailed(__('fastleo::messengers.not_member', [], Fastleo::LOCALE));
        }

        return ['id' => $id, 'title' => $this->string($chat['title'] ?? null) ?: $id];
    }

    #[Override]
    public function sendText(string $chatId, string $text): string
    {
        $message = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $this->shorten($text),
            'link_preview_options' => ['is_disabled' => true],
        ], sending: true);

        return $this->string($message['message_id'] ?? null);
    }

    /**
     * Пост в канал. Раскладка: без картинок — одно сообщение; одна картинка и подпись до 1024 символов — фото
     * с подписью и кнопкой; одна картинка и длинный текст — фото, затем текст с кнопкой; 2–10 картинок — альбом
     * (к альбому Telegram не даёт кнопку), затем текст с кнопкой. Шаги: `photo`, `album` (список), `text`.
     */
    #[Override]
    public function publish(string $chatId, string $html, array $images, ?array $button, array $done, Closure $step): void
    {
        $count = count($images);
        $layout = match (true) {
            $count === 0 => 'text',
            $count === 1 && self::visibleLength($html) <= self::CAPTION_LIMIT => 'photo',
            $count === 1 => 'photo+text',
            default => 'album+text',
        };
        $markup = $button === null ? null : json_encode(['inline_keyboard' => [[['text' => $button['label'], 'url' => $button['url']]]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($count === 1 && ! isset($done['photo'])) {
            $fields = $layout === 'photo' ? ['caption' => $html, 'parse_mode' => 'HTML', 'reply_markup' => $markup] : [];
            $message = $this->callWithFiles('sendPhoto', ['chat_id' => $chatId, ...$fields], ['photo' => $images[0]]);
            $step(['photo' => $this->string($message['message_id'] ?? null)], $layout);
        }

        if ($count > 1 && ! isset($done['album'])) {
            $files = [];

            foreach ($images as $index => $image) {
                $files['p'.$index] = $image;
            }

            $media = array_map(fn (string $name): array => ['type' => 'photo', 'media' => 'attach://'.$name], array_keys($files));
            $messages = $this->callWithFiles('sendMediaGroup', ['chat_id' => $chatId, 'media' => json_encode($media, JSON_THROW_ON_ERROR)], $files);
            $step(['album' => array_values(array_map(fn (mixed $message): string => $this->string(is_array($message) ? ($message['message_id'] ?? null) : null), $messages))], $layout);
        }

        if ($layout !== 'photo' && ! isset($done['text'])) {
            $message = $this->call('sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'link_preview_options' => $count > 0 ? ['is_disabled' => true] : null,
                'reply_markup' => $markup === null ? null : json_decode($markup, true),
            ], fn (mixed $value): bool => $value !== null), sending: true);
            $step(['text' => $this->string($message['message_id'] ?? null)], $layout);
        }
    }

    /**
     * Фото с подписью — `editMessageCaption` (подпись не длиннее 1024), иначе `editMessageText` текстового
     * сообщения. Ответ «message is not modified» — не ошибка.
     */
    #[Override]
    public function editPost(string $chatId, string $layout, array $messageIds, array $mediaTokens, string $html, ?array $button): void
    {
        $markup = ['inline_keyboard' => $button === null ? [] : [[['text' => $button['label'], 'url' => $button['url']]]]];
        $caption = $layout === 'photo';
        $id = $messageIds[$caption ? 'photo' : 'text'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new MessengerFailed(__('fastleo::messengers.post_unknown', [], Fastleo::LOCALE));
        }

        if ($caption && self::visibleLength($html) > self::CAPTION_LIMIT) {
            throw new MessengerFailed(__('fastleo::messengers.caption_too_long', ['max' => self::CAPTION_LIMIT], Fastleo::LOCALE));
        }

        try {
            $this->call($caption ? 'editMessageCaption' : 'editMessageText', [
                'chat_id' => $chatId,
                'message_id' => (int) $id,
                ($caption ? 'caption' : 'text') => $html,
                'parse_mode' => 'HTML',
                'reply_markup' => $markup,
            ]);
        } catch (MessengerFailed $exception) {
            if (! str_contains($exception->getMessage(), 'message is not modified')) {
                throw $exception;
            }
        }
    }

    /**
     * `deleteMessages`: все сообщения поста разом. Telegram удаляет только сообщения моложе 48 часов.
     */
    #[Override]
    public function deletePost(string $chatId, array $messageIds): void
    {
        $ids = array_map(intval(...), self::allIds($messageIds));

        if ($ids !== []) {
            $this->call('deleteMessages', ['chat_id' => $chatId, 'message_ids' => $ids]);
        }
    }

    /**
     * Длина видимого текста в единицах UTF-16 (так считает Telegram).
     */
    public static function visibleLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Вызвать метод с файлами (multipart). Файлы открываются до запроса: отсутствующий файл — не сбой связи.
     *
     * @param  array<string, string|null>  $fields
     * @param  array<string, array{name: string, open: Closure(): (resource|string)}>  $files
     * @return array<array-key, mixed>
     *
     * @throws MessengerFailed
     */
    private function callWithFiles(string $method, array $fields, array $files): array
    {
        $request = Http::connectTimeout(5)->timeout(120)->acceptJson()->asMultipart();

        foreach ($files as $name => $file) {
            $request = $request->attach($name, ($file['open'])(), $file['name']);
        }

        try {
            $response = $request->post(self::URL.$this->token.'/'.$method, array_filter($fields, fn (?string $value): bool => $value !== null));
        } catch (Throwable $exception) {
            throw $this->unreachable($exception, true);
        }

        if ($response->json('ok') === true) {
            $result = $response->json('result');

            return is_array($result) ? $result : [];
        }

        throw $this->failure($response);
    }

    /**
     * Вызвать метод Bot API и вернуть `result`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws MessengerFailed
     */
    private function call(string $method, array $payload = [], bool $sending = false): array
    {
        try {
            $response = $this->http()->post(self::URL.$this->token.'/'.$method, $payload);
        } catch (Throwable $exception) {
            throw $this->unreachable($exception, $sending);
        }

        if ($response->json('ok') === true) {
            $result = $response->json('result');

            return is_array($result) ? $result : [];
        }

        throw $this->failure($response);
    }

    /**
     * Ошибка из ответа Telegram: описание или код ответа; при 429 — сколько ждать.
     */
    private function failure(Response $response): MessengerFailed
    {
        $description = $response->json('description');
        $retryAfter = $response->json('parameters.retry_after');

        return new MessengerFailed(
            $this->clean(is_string($description) ? $description : 'HTTP '.$response->status()),
            retryAfter: is_int($retryAfter) ? $retryAfter : null,
        );
    }

    /**
     * Строка из числа или строки ответа.
     */
    private function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
