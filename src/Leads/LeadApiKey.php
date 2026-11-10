<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Fastleo;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Доступ стороннего сайта к приёму заявок (раздел «Доступы для сайтов»). Ключ хранится зашифрованным: пользователь панели
 * видит его в карточке и передаёт разработчику сайта вместе с инструкцией; для поиска по ключу есть хеш. Выключенный ключ
 * заявок не принимает. Ключ и хеш скрыты при выводе модели (логи, очереди, JSON).
 *
 * @property int $id
 * @property string $name
 * @property string|null $site
 * @property string $token
 * @property string $token_hash
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property string|null $note
 * @property-read string $visible_token
 * @property-read string $intake_url
 * @property-read string $instruction
 */
#[Fillable(['name', 'site', 'is_active', 'note'])]
#[Hidden(['token', 'token_hash'])]
#[Table(name: 'fastleo_lead_api_keys')]
final class LeadApiKey extends Model
{
    /** Начало ключа: по нему утёкший ключ находит поиск секретов (GitHub и другие). */
    private const string PREFIX = 'fllead_';

    /** Случайных знаков в ключе. */
    private const int LENGTH = 40;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /**
     * Новая запись сразу получает ключ.
     */
    protected static function booted(): void
    {
        self::creating(fn (self $key): self => $key->issue());
    }

    /**
     * Выдать новый ключ (старый перестаёт работать сразу): при создании и при действии «Перевыпустить». Сохранение — на вызывающем.
     */
    public function issue(): self
    {
        $token = self::PREFIX.Str::random(self::LENGTH);

        $this->token = $token;
        $this->token_hash = self::hash($token);

        return $this;
    }

    /**
     * Включённый ключ по значению из заголовка; неверный вид значения отвергается до обращения к базе.
     */
    public static function findActive(string $token): ?self
    {
        if (preg_match('/\A'.self::PREFIX.'[A-Za-z0-9]{'.self::LENGTH.'}\z/', $token) !== 1) {
            return null;
        }

        return self::query()->where('token_hash', self::hash($token))->where('is_active', true)->first();
    }

    /**
     * Отметить использование ключа; в базу пишется не чаще раза в минуту и без событий модели.
     */
    public function touchUsed(): void
    {
        if ($this->last_used_at instanceof Carbon && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        self::query()->whereKey($this->getKey())->update(['last_used_at' => now()]);
    }

    /**
     * Ключ для показа в карточке. После смены `APP_KEY` зашифрованный ключ не прочитать — вместо ошибки подсказка перевыпустить.
     *
     * @return Attribute<string, never>
     */
    protected function visibleToken(): Attribute
    {
        return Attribute::get(function (): string {
            try {
                $token = $this->getAttribute('token');
            } catch (DecryptException) {
                $token = null;
            }

            return is_string($token) ? $token : (string) __('fastleo::leads.token_unreadable', [], Fastleo::LOCALE);
        });
    }

    /**
     * Адрес, на который сайт отправляет заявки.
     *
     * @return Attribute<string, never>
     */
    protected function intakeUrl(): Attribute
    {
        return Attribute::get(fn (): string => url(config()->string('fastleo.leads.api_path', 'api/leads')));
    }

    /**
     * Инструкция для разработчика сайта целиком: её достаточно скопировать и отправить.
     *
     * @return Attribute<string, never>
     */
    protected function instruction(): Attribute
    {
        return Attribute::get(fn (): string => strtr((string) __('fastleo::leads.instruction', [], Fastleo::LOCALE), [
            '{{name}}' => $this->name,
            '{{endpoint}}' => $this->intake_url,
            '{{token}}' => $this->visible_token,
            '{{origin}}' => filled($this->site)
                ? __('fastleo::leads.instruction_origin_limited', ['site' => $this->site], Fastleo::LOCALE)
                : __('fastleo::leads.instruction_origin_open', [], Fastleo::LOCALE),
        ]));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => 'encrypted', 'is_active' => 'boolean', 'last_used_at' => 'datetime'];
    }

    /**
     * Хеш ключа для поиска (ключ случайный и длинный, соль не нужна).
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
