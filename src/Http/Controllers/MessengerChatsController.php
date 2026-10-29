<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Messengers\ChatIds;
use Fastleo\Messengers\Messenger;
use Fastleo\Messengers\MessengerFailed;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\RecordKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Кнопка «Найти чаты бота» поля `ChatIds`: чаты, где бот недавно появлялся.
 */
final class MessengerChatsController
{
    /**
     * `POST /fastleo/{resource}/chats/{field}` с `messenger`, `token` и (для сохранённой записи) `record`
     * → `{"chats": [{"id": "-100…", "title": "…", "type": "channel"}]}` или `{"error": "…"}` (422).
     *
     * Пустой токен — берётся сохранённый в записи (нужно право изменять её). Токен из запроса
     * проверяется по формату мессенджера; запрос уходит только на адрес API мессенджера.
     */
    public function __invoke(Request $request, string $resource, string $field): JsonResponse
    {
        $resource = Fastleo::resources()->find($resource);
        abort_unless($resource instanceof Resource, 404);
        Authorization::authorize('viewAny', $resource);

        $field = $resource->schema()->field($field);
        abort_unless($field instanceof ChatIds && $field->isFormField() && $field->isWritable(), 404);

        $messenger = Messenger::tryFrom((string) $request->string('messenger'));

        if (! $messenger instanceof Messenger) {
            return $this->error(__('fastleo::messengers.find_chats_messenger'));
        }

        $token = trim((string) $request->string('token'));

        if ($token === '') {
            $token = $this->savedToken($request, $resource, $field);
        }

        if ($token === '') {
            return $this->error(__('fastleo::messengers.find_chats_token'));
        }

        if (preg_match($messenger->tokenPattern(), $token) !== 1) {
            return $this->error(__('fastleo::messengers.token_invalid', ['messenger' => $messenger->label()]));
        }

        try {
            $chats = $messenger->bot($token)->recentChats();
        } catch (MessengerFailed $exception) {
            return $this->error(__('fastleo::messengers.find_chats_failed', ['reason' => $exception->getMessage()]));
        }

        $types = (array) __('fastleo::messengers.chat_types');

        return response()->json(['chats' => array_map(fn (array $chat): array => [
            'id' => $chat['id'],
            'title' => $chat['title'],
            'type' => is_string($types[$chat['type']] ?? null) ? $types[$chat['type']] : $chat['type'],
        ], $chats)]);
    }

    /**
     * Токен, сохранённый в записи (`record`): только если админ может её изменять.
     */
    private function savedToken(Request $request, Resource $resource, ChatIds $field): string
    {
        $key = $request->input('record');

        if (! is_string($key) || $key === '' || ! RecordKey::valid($resource->newModel(), $key)) {
            return '';
        }

        $record = $resource->newQuery()->whereKey($key)->first();
        abort_unless($record instanceof Model, 404);
        Authorization::authorize('update', $resource, $record);

        $token = $record->getAttribute($field->getTokenField());

        return is_string($token) ? trim($token) : '';
    }

    /**
     * Ответ с понятной ошибкой.
     */
    private function error(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 422);
    }
}
