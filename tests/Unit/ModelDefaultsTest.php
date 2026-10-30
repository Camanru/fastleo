<?php

declare(strict_types=1);

use Fastleo\Support\ModelDefaults;

it('takes a literal from the DEFAULT of a column in the formats of the databases', function (string $default, ?string $expected): void {
    expect(ModelDefaults::literal($default))->toBe($expected);
})->with([
    'quoted string' => ["'abc'", 'abc'],
    'escaped quote' => ["'it''s'", "it's"],
    'number' => ['42', '42'],
    'negative number' => ['-1', '-1'],
    'postgres boolean' => ['true', '1'],
    'postgres cast' => ["'x'::character varying", 'x'],
    'mysql unquoted string' => ['draft', 'draft'],
    'current timestamp' => ['CURRENT_TIMESTAMP', null],
    'function call' => ['uuid()', null],
    'null' => ['NULL', null],
    'zero date in quotes' => ["'0000-00-00 00:00:00'", null],
    'zero date as MySQL 8 reports it' => ['0000-00-00 00:00:00', null],
    'zero date only' => ['0000-00-00', null],
]);
