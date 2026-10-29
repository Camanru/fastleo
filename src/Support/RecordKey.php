<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Проверка ключа записи из запроса до обращения к базе.
 *
 * Число — только цифры (до 18), UUID и ULID (трейты `HasUuids`, `HasUlids`) — настоящий UUID/ULID,
 * остальные строковые ключи — латиница, цифры и дефис. Без проверки ключ неверного формата на PostgreSQL
 * даёт ошибку базы (500) вместо «не найдено».
 *
 * ```php
 * RecordKey::valid($resource->newModel(), $id); // false → 404
 * ```
 */
final class RecordKey
{
    /** Запасной формат строкового ключа без UUID/ULID. */
    private const string GENERIC = '/^[0-9A-Za-z-]{1,36}$/D';

    /**
     * Ключ допустимого формата для модели.
     */
    public static function valid(Model $model, string $value): bool
    {
        return self::strict($model, $value) ?? preg_match(self::GENERIC, $value) === 1;
    }

    /**
     * Соответствует ли значение формату ключа, если формат точно известен (число, UUID, ULID); иначе null.
     */
    public static function strict(Model $model, string $value): ?bool
    {
        if ($model->getKeyType() === 'int') {
            return preg_match('/^[0-9]{1,18}$/D', $value) === 1;
        }

        $traits = class_uses_recursive($model);

        return match (true) {
            isset($traits[HasUuids::class]) => Str::isUuid($value),
            isset($traits[HasUlids::class]) => Str::isUlid($value),
            default => null,
        };
    }
}
