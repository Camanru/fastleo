<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Уведомление пользователей панели о новой заявке (колокольчик, почта, боты — какие включены в `fastleo.notifications`).
 *
 * Заявка уже сохранена, поэтому сбой уведомления не должен показывать ошибку посетителю. Общий потолок — не больше
 * 20 уведомлений в минуту на источник (форма и каждый ключ API считаются отдельно: утёкший ключ не глушит форму и другие
 * сайты): пул адресов не завалит пользователей, сверх потолка заявки лежат в разделе без уведомления. В текст бота попадают
 * только имя (одной строкой, без ссылок, до 60 знаков) и телефон, но не сообщение и не адрес страницы: они приходят от посторонних.
 */
final class LeadNotifier
{
    /** Сколько уведомлений в минуту уходит на один источник. */
    private const int PER_MINUTE = 20;

    /** Длина имени в тексте уведомления. */
    private const int NAME_LENGTH = 60;

    /**
     * Сообщить о заявке; `$bucket` — счётчик потолка (`leads-notify` для формы, `leads-notify-api:{id ключа}` для API).
     */
    public static function send(Lead $lead, string $bucket = 'leads-notify'): void
    {
        try {
            RateLimiter::attempt($bucket, self::PER_MINUTE, fn () => Fastleo::notifyAdmins(
                FastleoMessage::make(__('fastleo::leads.notify_title', [], Fastleo::LOCALE))
                    ->body(self::oneLine($lead->name).', '.$lead->phone)
                    ->url(Fastleo::recordUrl($lead, false))
                    ->icon('inbox')
                    ->level('success'),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Строка без переводов строк, управляющих символов и ссылок, не длиннее имени в уведомлении. Слова с `http:`, `tg:`, `mailto:`, `//`
     * и `www.` убираются, после точки (и её юникод-вариантов `。．｡`) перед буквой ставится пробел (`evil.com` → `evil. com`): мессенджер не сделает из имени ссылку.
     */
    private static function oneLine(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $text);
        $text = (string) preg_replace('~\S*(?:(?:https?|ftp|tg|mailto):|//|www\.)\S*~iu', '', $text);
        $text = (string) preg_replace('/[.\x{3002}\x{FF0E}\x{FF61}](?=\pL)/u', '. ', $text);

        return Str::limit(trim((string) preg_replace('/ {2,}/', ' ', $text)), self::NAME_LENGTH, '');
    }
}
