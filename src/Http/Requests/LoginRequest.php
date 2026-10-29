<?php

declare(strict_types=1);

namespace Fastleo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Форма входа в админку.
 */
final class LoginRequest extends FormRequest
{
    /**
     * Правила формы входа: строки ограниченной длины, массивы вместо строк отклоняются.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Тексты ошибок из переводов пакета: сайт может не иметь lang/ru/validation.php.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('fastleo::auth.email_required'),
            'password.required' => __('fastleo::auth.password_required'),
            'email.*' => __('fastleo::auth.failed'),
            'password.*' => __('fastleo::auth.failed'),
            'remember.*' => __('fastleo::auth.failed'),
        ];
    }

    /**
     * Email в нижнем регистре без пробелов по краям.
     */
    public function email(): string
    {
        return Str::lower(trim($this->string('email')->toString()));
    }

    /**
     * Введённый пароль как есть.
     */
    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
