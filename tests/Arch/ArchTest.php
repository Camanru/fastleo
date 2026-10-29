<?php

declare(strict_types=1);
use Illuminate\Console\Command;

arch('every PHP file declares strict types')
    ->expect(['Fastleo', 'Workbench'])
    ->toUseStrictTypes();

arch('no debugging helpers are left in the code')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('env() is read only in config files')
    ->expect('env')
    ->not->toBeUsedIn(['Fastleo', 'Workbench']);

arch('controllers, middleware, requests and commands are final')
    ->expect([
        'Fastleo\Http\Controllers',
        'Fastleo\Http\Middleware',
        'Fastleo\Http\Requests',
        'Fastleo\Console',
        'Fastleo\Support',
        'Fastleo\Files',
    ])
    ->toBeFinal();

arch('the request is read through Laravel, not superglobals')
    ->expect('Fastleo')
    ->not->toUse(['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES']);

arch('commands extend the Laravel console command')
    ->expect('Fastleo\Console')
    ->toExtend(Command::class);
