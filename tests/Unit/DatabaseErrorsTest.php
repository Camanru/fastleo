<?php

declare(strict_types=1);

use Fastleo\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Exceptions;

/**
 * Ошибка запроса с кодом SQLSTATE и текстом драйвера.
 */
function queryFailure(string $message, string $state = 'HY000'): QueryException
{
    $driver = new PDOException($message);
    $driver->errorInfo = [$state, 1, $message];

    return new QueryException('sqlite', 'insert into things default values', [], $driver);
}

it('explains a database refusal in plain words and reports the original error', function (string $message, string $state, string $expected): void {
    Exceptions::fake();
    $exception = queryFailure($message, $state);

    expect(DatabaseErrors::explain($exception))->toBe(__('fastleo::resources.database_'.$expected));

    Exceptions::assertReported(fn (QueryException $reported): bool => $reported === $exception);
})->with([
    'foreign key by SQLSTATE' => ['constraint violation', '23503', 'foreign'],
    'foreign key by driver text' => ['FOREIGN KEY constraint failed', 'HY000', 'foreign'],
    'required column by SQLSTATE' => ['column is null', '23502', 'not_null'],
    'required column by driver text' => ['Column \'title\' cannot be null', 'HY000', 'not_null'],
    'long value by SQLSTATE' => ['value rejected', '22001', 'too_long'],
    'long value by driver text' => ['Data too long for column', 'HY000', 'too_long'],
    'anything else' => ['syntax error near "values"', '42000', 'other'],
]);

it('names the column that the database refused as a duplicate', function (string $message, string $sql, ?string $expected): void {
    Exceptions::fake();
    $driver = new PDOException($message);
    $driver->errorInfo = ['23000', 1062, $message];
    $exception = new UniqueConstraintViolationException('mysql', $sql, [], $driver);

    expect(DatabaseErrors::explain($exception))->toBe($expected === null
        ? __('fastleo::resources.database_unique')
        : __('fastleo::resources.database_unique_columns', ['columns' => $expected]));
})->with([
    'sqlite' => ['SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: articles.slug (Connection: sqlite)', 'insert into "articles"', 'slug'],
    'sqlite with two columns' => ['UNIQUE constraint failed: articles.slug, articles.locale', 'update "articles" set', 'slug, locale'],
    'postgres' => ['duplicate key value violates unique constraint "articles_slug_unique" DETAIL: Key (slug)=(about) already exists.', 'insert into "articles"', 'slug'],
    'mysql by the default name of the index' => ["Duplicate entry 'about' for key 'articles.articles_slug_unique'", 'insert into `articles` (`slug`) values (?)', 'slug'],
    'mysql 5 by the default name of the index' => ["Duplicate entry 'about' for key 'articles_slug_unique'", 'update `articles` set `slug` = ?', 'slug'],
    'mysql with a name of its own' => ["Duplicate entry 'about' for key 'my_index'", 'insert into `articles` (`slug`) values (?)', null],
    'mysql with a query we cannot read' => ["Duplicate entry 'about' for key 'articles_slug_unique'", 'replace into articles', null],
    'text we do not know' => ['unique violation', 'insert into articles', null],
]);
