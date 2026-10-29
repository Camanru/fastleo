<?php

declare(strict_types=1);

namespace Fastleo\Activity;

use Carbon\CarbonInterface;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Запись журнала: действие администратора над записью раздела.
 *
 * `changes` — `{атрибут: {old, new}}`; атрибуты из `$hidden` модели (пароль, токены) хранятся без значений
 * как `{hidden: true}`. `snapshot` — копия удалённой записи для восстановления.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string|null $user_name
 * @property string $resource
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property string $event created | updated | deleted | restored | force_deleted | reverted | import
 * @property array<string, array{old?: mixed, new?: mixed, hidden?: bool}>|null $changes
 * @property array<string, mixed>|null $snapshot
 * @property CarbonInterface|null $created_at
 */
#[Table('fastleo_activity')]
#[Unguarded]
#[WithoutTimestamps]
final class Activity extends Model
{
    /** События, после которых запись можно вернуть к прежнему состоянию. */
    public const array REVERTABLE = ['updated', 'reverted', 'deleted', 'force_deleted'];

    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Пустое значение: null, пустая строка или пустой массив. Смена одного пустого на другое — не изменение.
     */
    public static function isBlank(mixed $value): bool
    {
        return in_array($value, [null, '', []], true);
    }

    /**
     * Значение для показа в журнале: короткая строка («—» для пустого, «Да»/«Нет», теги вырезаются).
     * Список строк и чисел — через запятую, остальные массивы — числом элементов («3 элемента»).
     */
    public static function display(mixed $value): string
    {
        if (self::isBlank($value)) {
            return '—';
        }

        if (is_bool($value)) {
            return __($value ? 'fastleo::resources.yes' : 'fastleo::resources.no', [], Fastleo::LOCALE);
        }

        if (is_array($value)) {
            $value = array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === []
                ? implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value))
                : trans_choice('fastleo::activity.items', count($value), [], Fastleo::LOCALE);
        }

        return Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE))) ?? ''), 200, '…');
    }

    /**
     * Запись можно попытаться откатить (по типу события; состояние записи проверяется при откате).
     */
    public function isRevertable(): bool
    {
        return $this->subject_id !== null && in_array($this->event, self::REVERTABLE, true);
    }
}
