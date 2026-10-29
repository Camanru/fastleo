<?php

declare(strict_types=1);

namespace Fastleo\Notifications;

use Fastleo\Fastleo;
use Fastleo\Support\SeoTags;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * Уведомление администратору: заголовок, текст, ссылка и иконка. Показывается в колокольчике и уходит в чаты
 * включённых ботов раздела «Уведомления».
 *
 * ```php
 * Fastleo::notifyAdmins(
 *     FastleoMessage::make('Новая заявка')->body('Иван, +7 900 000-00-00')->url(route('fastleo.resources.show', ['leads', $lead->id]))->icon('inbox'),
 * );
 * ```
 *
 * Ссылка принимается только `http(s)://` и адресом с `/` — `javascript:` и подобное отбрасывается.
 */
final class FastleoMessage
{
    /** Уровни важности (цвет значка в колокольчике). */
    public const array LEVELS = ['info', 'success', 'warning', 'danger'];

    private string $body = '';

    private ?string $url = null;

    private string $icon = 'bell';

    private string $level = 'info';

    private function __construct(private readonly string $title) {}

    /**
     * Сообщение с заголовком (до 150 символов).
     */
    public static function make(string $title): self
    {
        return new self(Str::limit(trim($title), 149, '…'));
    }

    /**
     * Текст под заголовком (до 500 символов).
     */
    public function body(string $body): self
    {
        $this->body = Str::limit(trim($body), 499, '…');

        return $this;
    }

    /**
     * Ссылка при нажатии (только http(s) или адрес сайта с «/»).
     */
    public function url(?string $url): self
    {
        $this->url = $url !== null && preg_match('~^(https?://|/(?![/\\\\]))~i', $url) === 1 ? $url : null;

        return $this;
    }

    /**
     * Иконка Lucide.
     */
    public function icon(string $icon): self
    {
        $this->icon = preg_match('/^[a-z0-9-]{1,60}$/', $icon) === 1 ? $icon : 'bell';

        return $this;
    }

    /**
     * Важность: `info`, `success`, `warning` или `danger`.
     */
    public function level(string $level): self
    {
        $this->level = in_array($level, self::LEVELS, true) ? $level : 'info';

        return $this;
    }

    /**
     * Данные для хранения.
     *
     * @return array{title: string, body: string, url: ?string, icon: string, level: string}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'icon' => $this->icon, 'level' => $this->level];
    }

    /**
     * Текст для ботов уведомлений (Telegram, Max): заголовок, текст и ссылка с новой строки.
     */
    public function toText(): string
    {
        $url = $this->url === null ? null : (str_starts_with($this->url, '/') ? url($this->url) : $this->url);

        return implode("\n", array_filter([$this->title, $this->body, $url], fn (?string $line): bool => $line !== null && $line !== ''));
    }

    /**
     * Письмо администратору: заголовок — тема, текст — абзац, ссылка — кнопка «Открыть» (адрес сайта — от `APP_URL`).
     * Уровень `success` даёт зелёную кнопку, `danger` — красную. Заголовок и текст выводятся как обычный текст:
     * Laravel разбирает строки письма как Markdown, а они нередко приходят от посетителя сайта (текст заявки) —
     * без этого он мог бы вставить в письмо администратору свою ссылку или картинку.
     */
    public function toMail(): MailMessage
    {
        $mail = new MailMessage()
            ->subject($this->title)
            ->level(match ($this->level) {
                'success' => 'success',
                'danger' => 'error',
                default => 'info',
            })
            ->greeting($this->plain($this->title));

        if ($this->body !== '') {
            $mail->line($this->plain($this->body));
        }

        if ($this->url !== null) {
            $mail->action(__('fastleo::notifications.mail_open', locale: Fastleo::LOCALE), str_starts_with($this->url, '/') ? SeoTags::absolute($this->url) : $this->url);
        }

        return $mail;
    }

    /**
     * Текст без разметки Markdown: экранирует `\`, `[` и `]` — без них не получится ни ссылки `[текст](адрес)`, ни картинки.
     */
    private function plain(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '[' => '\\[', ']' => '\\]']);
    }
}
