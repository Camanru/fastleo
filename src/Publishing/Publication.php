<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Files\FileStore;
use Fastleo\Files\PathGuard;
use Fastleo\Messengers\MessengerFailed;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Публикация в каналы Telegram и Max: текст (Markdown), до 10 картинок, кнопка-ссылка, выбранные каналы.
 * По каждому каналу — доставка со своим статусом; статус публикации собирается из них.
 *
 * Статусы: `draft` (черновик), `scheduled` (ждёт времени), `sending`, `sent`, `partial` (часть каналов
 * с ошибкой), `failed`, `unknown` (связь оборвалась — пост мог дойти), `removed` (удалена из каналов).
 *
 * @property int $id
 * @property string $text
 * @property list<string>|null $images
 * @property string|null $button_label
 * @property string|null $button_url
 * @property list<string>|null $channel_ids
 * @property string|null $source_resource
 * @property string|null $source_id
 * @property string|null $user_id
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Delivery> $deliveries
 */
#[Fillable(['text', 'images', 'button_label', 'button_url', 'channel_ids', 'source_resource', 'source_id', 'user_id', 'status', 'scheduled_at'])]
#[Table(name: 'fastleo_publications')]
final class Publication extends Model
{
    /** Наибольшее число картинок. */
    public const int MAX_IMAGES = 10;

    /** Форматы картинок, которые принимают Telegram и Max. */
    public const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /**
     * Доставки по каналам.
     *
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Черновик или неудачная отправка: можно менять картинки и каналы и отправлять.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'failed'], true);
    }

    /**
     * Хотя бы в одном канале пост есть или мог появиться: такую публикацию нельзя удалить из журнала.
     */
    public function hasPosts(): bool
    {
        return $this->deliveries()->whereIn('status', ['sent', 'unknown', 'sending'])->exists();
    }

    /**
     * Отправить сейчас по всем ждущим каналам: задача `SendDelivery` на каждую доставку `pending`.
     */
    public function start(): void
    {
        $this->forceFill(['status' => 'sending', 'scheduled_at' => null])->save();

        foreach ($this->deliveries()->where('status', 'pending')->get() as $delivery) {
            dispatch(new SendDelivery($delivery))->afterCommit();
        }

        $this->refreshStatus();
    }

    /**
     * Отложенная отправка просрочена (задача ожидания, похоже, потерялась): можно отправить вручную.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_at?->lt(now()->subMinutes(5)) === true;
    }

    /**
     * HTML поста для мессенджеров.
     */
    public function html(): string
    {
        return MessageHtml::fromMarkdown($this->text);
    }

    /**
     * Кнопка-ссылка или null.
     *
     * @return array{label: string, url: string}|null
     */
    public function button(): ?array
    {
        return is_string($this->button_label) && $this->button_label !== '' && is_string($this->button_url) && $this->button_url !== ''
            ? ['label' => $this->button_label, 'url' => $this->button_url]
            : null;
    }

    /**
     * Картинки с диска файлов: имя и открытие потока (файл читается, только когда нужен).
     *
     * @return list<array{name: string, open: Closure(): (resource|string)}>
     */
    public function imageFiles(): array
    {
        return array_map(fn (string $path): array => [
            'name' => basename($path),
            'open' => function () use ($path) {
                try {
                    $stream = FileStore::disk()->readStream($path);
                } catch (Throwable) {
                    $stream = null;
                }

                return $stream ?? throw new MessengerFailed(__('fastleo::publishing.image_missing', ['path' => $path], Fastleo::LOCALE));
            },
        ], array_values(array_filter($this->images ?? [], is_string(...))));
    }

    /**
     * Папка копий картинок этой публикации на диске файлов: `uploads/publications/{id}`.
     */
    public function folder(): string
    {
        return PathGuard::root().'/publications/'.$this->id;
    }

    /**
     * Скопировать картинки черновика из записи сайта в папку публикации: публикация не зависит от того, что
     * потом станет с записью. Путь с `..` или не картинка пропускаются, отсутствующий файл — тоже.
     *
     * @return list<string> пути копий на диске файлов
     */
    public function copyImages(Post $post): array
    {
        $source = $post->disk() === null ? FileStore::disk() : Storage::disk($post->disk());
        $copies = [];

        foreach ($post->imagePaths() as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (count($copies) >= self::MAX_IMAGES || str_contains($path, '..') || str_contains($path, '\\') || ! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }

            try {
                $stream = $source->readStream(ltrim($path, '/'));
            } catch (Throwable) {
                $stream = null;
            }

            if (! is_resource($stream)) {
                continue;
            }

            $target = $this->folder().'/'.(count($copies) + 1).'.'.$extension;
            FileStore::disk()->writeStream($target, $stream);
            fclose($stream);
            $copies[] = $target;
        }

        return $copies;
    }

    /**
     * Собрать статус из доставок и сохранить.
     */
    public function refreshStatus(): void
    {
        $statuses = array_values(array_filter($this->deliveries()->pluck('status')->all(), is_string(...)));

        $this->status = match (true) {
            $statuses === [] => $this->status === 'scheduled' ? 'scheduled' : 'draft',
            array_intersect($statuses, ['pending', 'sending']) !== [] => $this->status === 'scheduled' && ! in_array('sending', $statuses, true) ? 'scheduled' : 'sending',
            array_diff($statuses, ['removed']) === [] => 'removed',
            in_array('unknown', $statuses, true) => 'unknown',
            array_diff($statuses, ['sent', 'removed']) === [] => 'sent',
            array_diff($statuses, ['failed']) === [] => 'failed',
            default => 'partial',
        };

        $this->save();
    }

    /**
     * Вместе с публикацией удаляется папка её копий картинок (после фиксации транзакции). Файлы, выбранные
     * из менеджера в других папках, не трогаются.
     */
    protected static function booted(): void
    {
        self::deleted(function (self $publication): void {
            $folder = $publication->folder();
            DB::afterCommit(fn (): bool => FileStore::disk()->deleteDirectory($folder));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'channel_ids' => 'array',
            'scheduled_at' => 'datetime',
        ];
    }
}
