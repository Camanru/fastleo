<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

/**
 * Фабрика пользователей демо-сайта. По умолчанию — обычный пользователь без доступа в админку.
 *
 * ```php
 * User::factory()->admin()->create(); // администратор с паролем «password»
 * ```
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * Обычный пользователь с паролем «password».
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Администратор fastleo.
     */
    public function admin(): self
    {
        return $this->state(['fastleo_admin' => true]);
    }
}
