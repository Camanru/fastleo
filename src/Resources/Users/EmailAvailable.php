<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;

/**
 * Адрес администратора свободен (без учёта регистра) и не меняет адрес самого вошедшего администратора.
 *
 * Ошибка добавляется напрямую в валидатор, а не через `$fail`: для поля `email` Laravel подставил бы
 * в замыкание текст правила `email` («должно быть адресом электронной почты») вместо настоящей причины.
 */
final class EmailAvailable implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    /**
     * @param  class-string<Model>  $model  модель пользователей
     * @param  Model|null  $record  редактируемая запись или `null` при создании
     */
    public function __construct(private readonly string $model, private readonly ?Model $record) {}

    /**
     * @param  Validator  $validator
     */
    #[\Override]
    public function setValidator(ValidatorContract $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $message = $this->problem($value);

        if ($message !== null) {
            $this->validator->errors()->add($attribute, $message);
        }
    }

    /**
     * Текст ошибки или `null`, если адрес подходит.
     */
    private function problem(string $value): ?string
    {
        $record = $this->record;
        $exists = $record instanceof Model && $record->exists;
        $email = Str::lower(trim($value));

        // Свой email меняют в профиле: там нужен текущий пароль, здесь его нет.
        if ($record instanceof Model && $exists && UserGuard::isCurrentUser($record)
            && $email !== Str::lower(is_string($record->getAttribute('email')) ? $record->getAttribute('email') : '')) {
            return __('fastleo::users.change_own_email_in_profile', [], Fastleo::LOCALE);
        }

        $taken = $this->model::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->when($exists, fn (Builder $query) => $query->whereKeyNot($record?->getKey()))
            ->exists();

        return $taken ? str_replace(':attribute', $this->validator->getDisplayableAttribute('email'), ValidationMessages::line('unique')) : null;
    }
}
