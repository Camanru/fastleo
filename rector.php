<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/config',
        __DIR__.'/routes',
        __DIR__.'/database',
        __DIR__.'/tests',
        __DIR__.'/workbench',
    ])
    ->withSkip([
        // Policy в тестах повторяют настоящие Policy сайта: первый параметр — пользователь, даже если не нужен.
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__.'/tests/Fixtures/Policies'],
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        LaravelSetList::LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    );
