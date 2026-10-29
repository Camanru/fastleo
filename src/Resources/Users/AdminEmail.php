<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Fastleo\Fields\Email;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

/**
 * Email администратора (колонка `email`): хранится в нижнем регистре (вход ищет адрес именно так), уникален без учёта регистра.
 */
final class AdminEmail extends Email
{
    /**
     * Шаблон формы — как у обычного поля `email`.
     */
    #[Override]
    public function type(): string
    {
        return 'email';
    }

    /**
     * Адрес в нижнем регистре без пробелов по краям.
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = parent::dehydrate($value);

        return is_string($value) ? Str::lower($value) : $value;
    }

    /**
     * Правила поля и уникальность без учёта регистра.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $rules[$this->name][] = new EmailAvailable($this->model()::class, $record);

        return $rules;
    }
}
