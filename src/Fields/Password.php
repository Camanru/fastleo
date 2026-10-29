<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Override;

/**
 * Пароль. Форма всегда пустая; пустое поле при редактировании оставляет старый пароль.
 * В модель пишется хеш (`Hash::make`), требования к паролю — `Password::defaults()` сайта.
 * В списке и на просмотре не показывается.
 *
 * ```php
 * Password::make('password', 'Пароль')->required()->confirmed();
 * ```
 */
class Password extends Field
{
    /** Пароль не показывается в списке. */
    protected bool $showInList = false;

    /** Нужен повтор пароля во втором поле. */
    protected bool $confirmed = false;

    /**
     * Попросить ввести пароль дважды (второе поле `{имя}_confirmation`).
     *
     * ```php
     * Password::make('password')->confirmed();
     * ```
     */
    public function confirmed(bool $confirmed = true): static
    {
        $this->confirmed = $confirmed;

        return $this;
    }

    /**
     * Нужен повтор пароля.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * `required()` действует только при создании записи: при редактировании пустое поле — «не менять».
     */
    #[Override]
    protected function presenceRule(?Model $record): string
    {
        return $this->required && (! $record instanceof Model || ! $record->exists) ? 'required' : 'nullable';
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['string', PasswordRule::default(), ...($this->confirmed ? ['confirmed'] : [])];
    }

    /**
     * Хеш в форму не попадает.
     */
    #[Override]
    public function hydrate(Model $record): mixed
    {
        return null;
    }

    #[Override]
    protected function formatDefault(mixed $value): mixed
    {
        return null;
    }

    /**
     * Пароль не обрезается по краям: пробелы — часть пароля.
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /**
     * Пустое значение не меняет пароль, иначе в модель пишется хеш.
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        if (is_string($value)) {
            $record->fill([$this->name => Hash::make($value)]);
        }
    }

    #[Override]
    public function isVisibleOnShow(Model $record): bool
    {
        return false;
    }

    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        return null;
    }

    /**
     * Не выгружается в Excel/CSV.
     */
    #[Override]
    public function isExportable(): bool
    {
        return false;
    }
}
