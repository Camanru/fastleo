<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Доставка публикации в один канал.
 *
 * Статусы: `pending` (ждёт отправки), `sending`, `sent`, `failed`, `unknown` (связь оборвалась после
 * отправки — пост мог дойти, автоповтора нет), `removed` (удалена из канала).
 *
 * `message_ids` — номера отправленных сообщений по ролям (`album`, `photo`, `text`, `message`): сохраняются
 * сразу после каждого шага, поэтому повтор продолжает с места сбоя, а правка и удаление находят пост.
 * `media_tokens` — токены загруженных в Max картинок (для правки кнопки и повтора без новой загрузки).
 *
 * @property int $id
 * @property int $publication_id
 * @property int $channel_id
 * @property string $status
 * @property string|null $layout
 * @property array<string, string|list<string>>|null $message_ids
 * @property list<string>|null $media_tokens
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $sent_at
 * @property-read Publication $publication
 * @property-read Channel $channel
 */
#[Fillable(['publication_id', 'channel_id', 'status', 'layout', 'message_ids', 'media_tokens', 'error', 'started_at', 'sent_at'])]
#[Table(name: 'fastleo_publication_deliveries')]
final class Delivery extends Model
{
    /** Через сколько минут отправка считается зависшей (процесс убит) и её можно повторить. */
    public const int STUCK_MINUTES = 10;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /**
     * Публикация.
     *
     * @return BelongsTo<Publication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /**
     * Канал.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Отправку можно повторить: ошибка, «могло дойти» или зависшая отправка.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, ['failed', 'unknown'], true)
            || ($this->status === 'sending' && $this->started_at?->lt(now()->subMinutes(self::STUCK_MINUTES)) === true);
    }

    /**
     * Запомнить номера отправленных сообщений сразу после шага отправки.
     *
     * @param  array<string, string|list<string>>  $ids
     */
    public function remember(array $ids, ?string $layout = null): void
    {
        $this->message_ids = [...($this->message_ids ?? []), ...$ids];
        $this->layout = $layout ?? $this->layout;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'media_tokens' => 'array',
            'started_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
