<?php

declare(strict_types=1);

use Fastleo\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
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
