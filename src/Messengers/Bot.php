<?php

declare(strict_types=1);

namespace Fastleo\Messengers;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Бот мессенджера: проверка токена, поиск чатов, где бот появлялся, отправка сообщений.
 *
 * Токен никогда не попадает в тексты ошибок: все сбои превращаются в `MessengerFailed` с очищенным
 * текстом и без исходного исключения.
 *
 * ```php
 * $bot = Messenger::Telegram->bot($token);
 * $bot->me();                          // ['id' => '123', 'name' => '@site_bot']
 * $bot->sendText('-100200300', 'Привет');
 * ```
 */
abstract class Bot
{
    /** Самый длинный текст, который отправляется (меньший из лимитов Telegram и Max). */
    public const int TEXT_LIMIT = 4000;

    public function __construct(#[SensitiveParameter] protected readonly string $token) {}

    /**
     * Мессенджер бота.
     */
    abstract public function messenger(): Messenger;

    /**
     * Проверить токен: id и имя бота.
     *
     * @return array{id: string, name: string}
     *
     * @throws MessengerFailed
     */
    abstract public function me(): array;

    /**
     * Чаты, каналы и диалоги, где бот недавно появлялся (его добавили или ему написали), — для выбора в форме.
     *
     * @return list<array{id: string, title: string, type: string}>
     *
     * @throws MessengerFailed
     */
    abstract public function recentChats(): array;

    /**
     * Проверить канал (или группу) для публикаций: бот в нём состоит, а в канале — администратор с правами
     * публиковать, править и удалять посты. Возвращает постоянный номер чата и название.
     *
     * @return array{id: string, title: string}
     *
     * @throws MessengerFailed что не так — понятным текстом
     */
    abstract public function checkChannel(string $chatId): array;

    /**
     * Отправить простой текст (без разметки) в чат. Слишком длинный текст обрезается. Возвращает id сообщения.
     *
     * @throws MessengerFailed
     */
    abstract public function sendText(string $chatId, string $text): string;

    /**
     * Опубликовать пост: HTML-текст (теги, которые понимают оба мессенджера), картинки и кнопка-ссылка.
     *
     * Каждый шаг (альбом, текст, загрузка картинок в Max) сообщается в `$step` сразу после успеха — вызывающий
     * сохраняет его. `$done` — что уже отправлено раньше (те же ключи): эти шаги пропускаются, поэтому повтор
     * после сбоя не дублирует сообщения.
     *
     * @param  list<array{name: string, open: Closure(): (resource|string)}>  $images
     * @param  array{label: string, url: string}|null  $button
     * @param  array<string, mixed>  $done
     * @param  Closure(array<string, mixed>, string): void  $step  что отправлено и раскладка поста
     *
     * @throws MessengerFailed
     */
    abstract public function publish(string $chatId, string $html, array $images, ?array $button, array $done, Closure $step): void;

    /**
     * Обновить текст и кнопку отправленного поста. Картинки не меняются.
     *
     * @param  array<string, mixed>  $messageIds  номера сообщений поста по ролям (как сохранил `publish()`)
     * @param  list<string>  $mediaTokens  токены картинок Max (для Telegram не нужны)
     * @param  array{label: string, url: string}|null  $button
     *
     * @throws MessengerFailed
     */
    abstract public function editPost(string $chatId, string $layout, array $messageIds, array $mediaTokens, string $html, ?array $button): void;

    /**
     * Удалить пост из канала (все его сообщения).
     *
     * @param  array<string, mixed>  $messageIds
     *
     * @throws MessengerFailed
     */
    abstract public function deletePost(string $chatId, array $messageIds): void;

    /**
     * Все номера сообщений поста списком.
     *
     * @param  array<string, mixed>  $messageIds
     * @return list<string>
     */
    protected static function allIds(array $messageIds): array
    {
        $ids = [];

        foreach ($messageIds as $role => $value) {
            if ($role === 'media') {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Клиент HTTP с таймаутами.
     */
    protected function http(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(15)->acceptJson()->asJson();
    }

    /**
     * Ошибка «у бота нет прав» со списком недостающих (`post`, `edit`, `delete`).
     *
     * @param  list<string>  $missing
     */
    protected function rightsMissing(array $missing): MessengerFailed
    {
        $names = array_map(fn (string $right): string => __('fastleo::messengers.right_'.$right, [], Fastleo::LOCALE), $missing);

        return new MessengerFailed(__('fastleo::messengers.rights_missing', ['rights' => implode(', ', $names)], Fastleo::LOCALE));
    }

    /**
     * Убрать токен из текста.
     */
    protected function clean(string $text): string
    {
        return $this->token === '' ? $text : str_replace($this->token, '***', $text);
    }

    /**
     * Текст не длиннее лимита.
     */
    protected function shorten(string $text): string
    {
        return mb_strlen($text) > self::TEXT_LIMIT ? mb_substr($text, 0, self::TEXT_LIMIT - 1).'…' : $text;
    }

    /**
     * Ошибка связи. При отправке (`$sending`) сообщение могло дойти, если обрыв случился после соединения:
     * точно не дошло только при ошибке DNS, отказе в соединении или сбое TLS (коды cURL 6, 7, 35, 60).
     */
    protected function unreachable(Throwable $exception, bool $sending): MessengerFailed
    {
        $notConnected = $exception instanceof ConnectionException
            && preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1
            && in_array((int) $match[1], [6, 7, 35, 60], true);

        return new MessengerFailed(
            __('fastleo::messengers.unreachable', ['messenger' => $this->messenger()->label()], Fastleo::LOCALE),
            uncertain: $sending && ! $notConnected,
        );
    }
}
