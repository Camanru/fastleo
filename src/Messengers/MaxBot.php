<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Override;
use Throwable;

/**
 * Бот Max (Bot API `platform-api2.max.ru`). Токен — в заголовке `Authorization`.
 *
 * Сервер API подписан корневым сертификатом Минцифры (Russian Trusted Root CA), которого нет
 * в обычных хранилищах. Пакет доверяет ему **только в запросах к API Max**: сертификат лежит
 * в `resources/certs`, свой путь — `fastleo.messengers.max.certificate`.
 *
 * ```php
 * (new MaxBot($token))->sendText('-71234567', 'Привет');
 * ```
 */
final class MaxBot extends Bot
{
    #[Override]
    public function messenger(): Messenger
    {
        return Messenger::Max;
    }

    /**
     * Сертификат, которому доверяют запросы к API Max.
     */
    public static function certificate(): string
    {
        $path = config('fastleo.messengers.max.certificate');

        return is_string($path) && $path !== '' ? $path : dirname(__DIR__, 2).'/resources/certs/russian-trusted-root-ca.pem';
    }

    #[Override]
    public function me(): array
    {
        $me = $this->request('get', '/me');
        $username = $me['username'] ?? null;

        return ['id' => $this->string($me['user_id'] ?? null), 'name' => is_string($username) && $username !== '' ? '@'.$username : $this->string($me['name'] ?? null)];
    }

    /**
     * Чаты из последних событий бота (`GET /updates`: бота добавили в чат или канал, боту написали).
     * Названия групп и каналов — запросом `GET /chats/{id}`. Не работает, если у бота настроен webhook.
     */
    #[Override]
    public function recentChats(): array
    {
        $updates = $this->request('get', '/updates', query: ['limit' => 100, 'timeout' => 0, 'types' => 'bot_added,message_created']);
        $chats = [];

        foreach (is_array($updates['updates'] ?? null) ? $updates['updates'] : [] as $update) {
            if (data_get($update, 'update_type') === 'bot_added') {
                $id = $this->string(data_get($update, 'chat_id'));
                $type = data_get($update, 'is_channel') === true ? 'channel' : 'chat';
                $title = '';
            } else {
                $id = $this->string(data_get($update, 'message.recipient.chat_id'));
                $type = $this->string(data_get($update, 'message.recipient.chat_type'));
                $title = $type === 'dialog' ? $this->string(data_get($update, 'message.sender.name')) : '';
            }

            if ($id !== '' && ! isset($chats[$id])) {
                $chats[$id] = ['id' => $id, 'title' => $title, 'type' => $type];
            }
        }

        return array_values(array_map(fn (array $chat): array => $chat['title'] === '' ? [...$chat, 'title' => $this->chatTitle($chat['id'])] : $chat, $chats));
    }

    /**
     * `GET /me` (токен), `GET /chats/{id}` (чат активен, название) и `GET /chats/{id}/members/me`: в канале бот —
     * администратор с правами `write`, `edit`, `delete` (или их старыми названиями).
     */
    #[Override]
    public function checkChannel(string $chatId): array
    {
        $this->me();
        $chat = $this->request('get', '/chats/'.rawurlencode($chatId));

        if (($chat['status'] ?? 'active') !== 'active') {
            throw new MessengerFailed(__('fastleo::messengers.chat_inactive', [], Fastleo::LOCALE));
        }

        if (($chat['type'] ?? null) === 'channel') {
            $member = $this->request('get', '/chats/'.rawurlencode($chatId).'/members/me');

            if (($member['is_admin'] ?? false) !== true) {
                throw new MessengerFailed(__('fastleo::messengers.not_admin', [], Fastleo::LOCALE));
            }

            $permissions = array_filter(is_array($member['permissions'] ?? null) ? $member['permissions'] : [], is_string(...));
            $missing = array_keys(array_filter(
                ['post' => ['write', 'post_edit_delete_message'], 'edit' => ['edit', 'edit_message'], 'delete' => ['delete', 'delete_message']],
                fn (array $names): bool => array_intersect($names, $permissions) === [],
            ));

            if ($missing !== []) {
                throw $this->rightsMissing($missing);
            }
        }

        $id = $this->string($chat['chat_id'] ?? null) ?: $chatId;
        $title = $chat['title'] ?? null;

        return ['id' => $id, 'title' => is_string($title) && $title !== '' ? $title : $id];
    }

    #[Override]
    public function sendText(string $chatId, string $text): string
    {
        $data = $this->request('post', '/messages', ['text' => $this->shorten($text)], ['chat_id' => $chatId], sending: true);
        $id = data_get($data, 'message.body.mid');

        return is_string($id) && $id !== '' ? $id : throw new MessengerFailed(__('fastleo::messengers.bad_response', ['messenger' => 'Max'], Fastleo::LOCALE));
    }

    /**
     * Пост в канал одним сообщением: текст (HTML), картинки и кнопка-ссылка. Шаги: `media` (токены загруженных
     * картинок — при повторе картинки не загружаются заново), `message`. Пока Max обрабатывает картинки
     * (`attachment.not.ready`), отправка повторяется до трёх раз с паузой в секунду.
     */
    #[Override]
    public function publish(string $chatId, string $html, array $images, ?array $button, array $done, Closure $step): void
    {
        if (isset($done['message'])) {
            return;
        }

        $tokens = is_array($done['media'] ?? null) ? array_values(array_filter($done['media'], is_string(...))) : null;

        if ($tokens === null || count($tokens) !== count($images)) {
            $tokens = array_map($this->upload(...), $images);
            $step(['media' => $tokens], 'message');
        }

        $body = ['text' => $html, 'format' => 'html', 'attachments' => self::attachments($tokens, $button)];

        for ($attempt = 1; ; $attempt++) {
            try {
                $data = $this->request('post', '/messages', $body, ['chat_id' => $chatId], sending: true);

                break;
            } catch (MessengerFailed $exception) {
                if ($attempt >= 3 || ! str_contains($exception->getMessage(), 'attachment.not.ready')) {
                    throw $exception;
                }

                Sleep::for(1)->second();
            }
        }

        $id = data_get($data, 'message.body.mid');

        if (! is_string($id) || $id === '') {
            throw new MessengerFailed(__('fastleo::messengers.bad_response', ['messenger' => 'Max'], Fastleo::LOCALE));
        }

        $step(['message' => $id], 'message');
    }

    /**
     * `PUT /messages`: текст и вложения целиком — картинки по сохранённым токенам и новая кнопка (кнопка в Max —
     * тоже вложение, поэтому без картинок её не поменять). Ответ проверяется по `success`.
     */
    #[Override]
    public function editPost(string $chatId, string $layout, array $messageIds, array $mediaTokens, string $html, ?array $button): void
    {
        $id = $messageIds['message'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new MessengerFailed(__('fastleo::messengers.post_unknown', [], Fastleo::LOCALE));
        }

        $this->confirm($this->request('put', '/messages', ['text' => $html, 'format' => 'html', 'attachments' => self::attachments($mediaTokens, $button)], ['message_id' => $id]));
    }

    /**
     * `DELETE /messages` по каждому сообщению поста; ответ проверяется по `success`.
     */
    #[Override]
    public function deletePost(string $chatId, array $messageIds): void
    {
        foreach (self::allIds($messageIds) as $id) {
            $this->confirm($this->request('delete', '/messages', query: ['message_id' => $id]));
        }
    }

    /**
     * Max отвечает 200 и на неудачу: `{"success": false, "message": "…"}`.
     *
     * @param  array<array-key, mixed>  $answer
     *
     * @throws MessengerFailed
     */
    private function confirm(array $answer): void
    {
        if (($answer['success'] ?? null) !== true) {
            $message = $answer['message'] ?? null;

            throw new MessengerFailed($this->clean(is_string($message) && $message !== '' ? $message : __('fastleo::messengers.bad_response', ['messenger' => 'Max'], Fastleo::LOCALE)));
        }
    }

    /**
     * Вложения сообщения: картинки по токенам и клавиатура с одной кнопкой-ссылкой.
     *
     * @param  list<string>  $tokens
     * @param  array{label: string, url: string}|null  $button
     * @return list<array<string, mixed>>
     */
    public static function attachments(array $tokens, ?array $button): array
    {
        $attachments = array_map(fn (string $token): array => ['type' => 'image', 'payload' => ['token' => $token]], $tokens);

        if ($button !== null) {
            $attachments[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'link', 'text' => $button['label'], 'url' => $button['url']]]]]];
        }

        return $attachments;
    }

    /**
     * Загрузить картинку: `POST /uploads?type=image` даёт адрес загрузки (только https, без перенаправлений,
     * обычные сертификаты), туда уходит файл, в ответ — токен картинки.
     *
     * @param  array{name: string, open: Closure(): (resource|string)}  $image
     *
     * @throws MessengerFailed
     */
    private function upload(array $image): string
    {
        $url = $this->request('post', '/uploads', query: ['type' => 'image'])['url'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new MessengerFailed(__('fastleo::messengers.bad_response', ['messenger' => 'Max'], Fastleo::LOCALE));
        }

        $contents = ($image['open'])();

        try {
            $response = Http::connectTimeout(5)->timeout(120)->acceptJson()->withOptions(['allow_redirects' => false])
                ->attach('data', $contents, $image['name'])->post($url);
        } catch (Throwable $exception) {
            throw $this->unreachable($exception, false);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $token = data_get($response->json(), 'token');
        $photos = data_get($response->json(), 'photos');

        foreach (is_array($photos) ? $photos : [] as $photo) {
            $token = is_string($token) ? $token : data_get($photo, 'token');
        }

        return is_string($token) && $token !== '' ? $token : throw new MessengerFailed(__('fastleo::messengers.bad_response', ['messenger' => 'Max'], Fastleo::LOCALE));
    }

    /**
     * Название чата или его номер, если Max не отдал название.
     */
    private function chatTitle(string $id): string
    {
        try {
            $title = $this->request('get', '/chats/'.rawurlencode($id))['title'] ?? null;
        } catch (MessengerFailed) {
            $title = null;
        }

        return is_string($title) && $title !== '' ? $title : $id;
    }

    /**
     * Запрос к API. Ошибка (код не 2xx) — `MessengerFailed` с текстом Max.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws MessengerFailed
     */
    private function request(string $method, string $path, array $body = [], array $query = [], bool $sending = false): array
    {
        try {
            $http = $this->api()->withQueryParameters($query);
            $response = $method === 'get' ? $http->get($path) : $http->send(strtoupper($method), $path, ['json' => $body]);
        } catch (Throwable $exception) {
            throw $this->unreachable($exception, $sending);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Клиент API Max: адрес, токен, сертификат Минцифры.
     */
    private function api(): PendingRequest
    {
        return $this->http()
            ->baseUrl(rtrim(config()->string('fastleo.messengers.max.url', 'https://platform-api2.max.ru'), '/'))
            ->withHeaders(['Authorization' => $this->token])
            ->withOptions(['verify' => self::certificate()]);
    }

    /**
     * Ошибка из ответа Max: текст `message` или код ответа; при 429 — подождать секунду.
     */
    private function failure(Response $response): MessengerFailed
    {
        $message = $response->json('message');
        $code = $response->json('code');
        $text = is_string($message) && $message !== '' ? $message : (is_string($code) ? $code : 'HTTP '.$response->status());

        return new MessengerFailed($this->clean($text), retryAfter: $response->status() === 429 ? 1 : null);
    }

    /**
     * Строка из числа или строки ответа.
     */
    private function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
