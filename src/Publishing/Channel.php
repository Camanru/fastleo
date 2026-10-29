<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use BackedEnum;
use Closure;
use Fastleo\Fastleo;
use Fastleo\Messengers\Bot;
use Fastleo\Messengers\Messenger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Канал Telegram или Max, куда публикуются записи сайта (раздел «Каналы»). Токен бота хранится зашифрованным
 * и скрыт при выводе модели.
 *
 * @property int $id
 * @property string $name
 * @property Messenger $messenger
 * @property string $token
 * @property string $chat_id
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'messenger', 'token', 'chat_id', 'enabled'])]
#[Hidden(['token'])]
#[Table(name: 'fastleo_channels')]
final class Channel extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => true];

    /** Ключ в контейнере: есть ли включённый канал (запоминается на время запроса). */
    private const string ANY_ENABLED = 'fastleo.channels.any-enabled';

    /**
     * Есть хотя бы один включённый канал: тогда видны «Публикации» и кнопка публикации в разделах сайта.
     * Таблицы ещё нет (не выполнены миграции) — каналов нет.
     */
    public static function anyEnabled(): bool
    {
        if (! app()->bound(self::ANY_ENABLED)) {
            try {
                app()->instance(self::ANY_ENABLED, self::query()->where('enabled', true)->exists());
            } catch (QueryException) {
                return false;
            }
        }

        return resolve(self::ANY_ENABLED) === true;
    }

    /**
     * Включённые каналы для выбора в публикации: номер записи → «Название (Мессенджер)».
     *
     * @return array<string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::query()->where('enabled', true)->orderBy('name')->get() as $channel) {
            $options[$channel->id] = $channel->name.' ('.$channel->messenger->label().')';
        }

        return $options;
    }

    /**
     * Через канал уже отправляли посты.
     */
    public function hasDeliveries(): bool
    {
        return Delivery::query()->where('channel_id', $this->getKey())->exists();
    }

    /**
     * Правило: мессенджер и номер канала нельзя менять, если через канал уже отправляли посты. Номера сообщений
     * Telegram свои в каждом чате: «Удалить из каналов» по новому номеру чата удалила бы чужие посты.
     */
    public static function keepsAfterDelivery(?Model $record, string $column): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record, $column): void {
            if (! $record instanceof self || ! $record->exists || ! $record->hasDeliveries()) {
                return;
            }

            $old = $record->getAttribute($column);
            $old = $old instanceof BackedEnum ? $old->value : $old;
            $new = is_array($value) ? reset($value) : $value;

            if (! is_string($new) || $new !== $old) {
                $fail(__('fastleo::publishing.channel_locked', [], Fastleo::LOCALE));
            }
        };
    }

    /**
     * Клиент бота канала.
     */
    public function bot(): Bot
    {
        return $this->messenger->bot($this->token);
    }

    /**
     * Сохранение и удаление канала меняют ответ `anyEnabled()`.
     */
    protected static function booted(): void
    {
        self::saved(fn () => app()->forgetInstance(self::ANY_ENABLED));
        self::deleted(fn () => app()->forgetInstance(self::ANY_ENABLED));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messenger' => Messenger::class,
            'token' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }
}
