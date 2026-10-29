<?php

declare(strict_types=1);

namespace Fastleo\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Базовый тест: демо-сайт из workbench (провайдер пакета, модель User, миграции) и чистая база.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;
}
