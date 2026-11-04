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
    'mysql unquoted colour' => ['#ffffff', '#ffffff'],
    'mysql unquoted list' => ['a,b', 'a,b'],
    'mysql unquoted path' => ['x/y', 'x/y'],
    'mysql unquoted address' => ['user@site.ru', 'user@site.ru'],
    'mysql unquoted sentence' => ['(нет данных)!', '(нет данных)!'],
    'empty string' => ["''", ''],
    'current timestamp' => ['CURRENT_TIMESTAMP', null],
    'current timestamp with precision' => ['CURRENT_TIMESTAMP(6)', null],
    'mariadb function' => ['current_timestamp()', null],
    'sqlite expression' => ["(datetime('now'))", null],
    'postgres sequence' => ["nextval('articles_id_seq'::regclass)", null],
    'function call' => ['uuid()', null],
    'null' => ['NULL', null],
    'mysql expression with a string' => ["_utf8mb4\\'hello\\'", null],
    'mysql expression with an empty json' => ["_latin1\\'[]\\'", null],
    'mysql negative expression' => ['-(1)', null],
    'mysql bit literal' => ["b'0'", null],
    'postgres function with a schema' => ['extensions.uuid_generate_v4()', null],
    'postgres empty array' => ['ARRAY[]', null],
    'postgres array of strings' => ["ARRAY['a'::text]", null],
    'postgres enum with a schema' => ["'draft'::public.article_status", 'draft'],
    'postgres quoted type' => ['\'x\'::"char"', 'x'],
    'zero date in quotes' => ["'0000-00-00 00:00:00'", null],
    'zero date as MySQL 8 reports it' => ['0000-00-00 00:00:00', null],
    'zero date only' => ['0000-00-00', null],
]);
