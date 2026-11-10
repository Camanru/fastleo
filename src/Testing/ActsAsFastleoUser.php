<?php

declare(strict_types=1);

namespace Fastleo\Testing;

use Fastleo\Fastleo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase;

/**
 * Для TestCase сайта: войти в панель под нужным пользователем, в том числе сменить его посреди теста (владелец, потом менеджер).
 *
 * Простой `actingAs($user, 'fastleo')` второй раз в том же тесте не меняет вошедшего: guard и сессия помнят первого.
 * `actingAsFastleo()` сначала сбрасывает и то и другое.
 *
 * ```php
 * use Fastleo\Testing\ActsAsFastleoUser;
 *
 * $this->actingAsFastleo($manager)->get('/leads')->assertOk();
 * $this->actingAsFastleo($owner)->get('/users')->assertOk();
 * ```
 *
 * @phpstan-require-extends TestCase
 */
trait ActsAsFastleoUser
{
    /**
     * Войти в панель (guard `fastleo`) пользователем, забыв предыдущего.
     */
    public function actingAsFastleo(Authenticatable $user): static
    {
        resolve('auth')->forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, Fastleo::GUARD);
    }
}
