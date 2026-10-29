<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Toggle;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Галочка «Доступ в админку» (колонка `fastleo_admin`).
 *
 * Колонки нет в `$fillable` модели намеренно (доступ нельзя выдать массовым присваиванием), поэтому поле пишет её
 * через `forceFill` и не требует `$fillable`. Нельзя снять доступ у себя и у последнего администратора
 * и создать администратора без доступа.
 */
final class AdminAccess extends Toggle
{
    /**
     * Шаблон формы — как у обычного переключателя.
     */
    #[Override]
    public function type(): string
    {
        return 'toggle';
    }

    /**
     * Колонка не из `$fillable`: проверять разрешение модели не нужно.
     */
    #[Override]
    public function writesAttribute(): bool
    {
        return false;
    }

    /**
     * Записать флаг в обход `$fillable`.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        $record->forceFill([$this->name => $value]);
    }

    /**
     * Правила галочки и запрет снять доступ у себя и у последнего администратора.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);

        if (! $record instanceof Model || ! $record->exists) {
            // Раздел показывает только администраторов: без доступа новая запись сразу пропала бы из списка.
            $rules[$this->name][] = function (string $attribute, mixed $value, Closure $fail): void {
                if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $fail(__('fastleo::users.access_required_for_new', [], Fastleo::LOCALE));
                }
            };
        }

        if ($record instanceof Model && $record->exists) {
            $rules[$this->name][] = function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                if (UserGuard::isCurrentUser($record)) {
                    $fail(__('fastleo::users.cannot_remove_own_access', [], Fastleo::LOCALE));
                } elseif (UserGuard::isLastAdmin($record)) {
                    $fail(__('fastleo::users.last_admin', [], Fastleo::LOCALE));
                }
            };
        }

        return $rules;
    }
}
