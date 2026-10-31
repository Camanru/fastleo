<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Pdo\Sqlite;

/**
 * Условие «колонка содержит подстроку без учёта регистра»: `col like ? escape '!'` (PostgreSQL —
 * `cast(col as text) ilike ? escape '!'`; JSON-путь `col->ru` в MySQL — `lower(...) like lower(?)`: значение из JSON
 * там сравнивается с учётом регистра; SQLite — `fastleo_lower(...) like fastleo_lower(?)`: встроенные `lower()` и
 * `like` SQLite не различают регистр только у латиницы, а `fastleo_lower` — `mb_strtolower` PHP).
 *
 * Имя колонки экранирует грамматика БД; значение передаётся привязкой (`?`).
 * Шаблон для привязки готовит `pattern()`: `%` и `_` в строке поиска ищутся как обычные символы.
 *
 * ```php
 * ContainsExpression::where($query, 'title', 'новост'); // найдёт «Новости»
 * ```
 */
final readonly class ContainsExpression implements Expression
{
    /** Символ экранирования в LIKE: одинаково работает в SQLite, MySQL и PostgreSQL. */
    public const string ESCAPE = '!';

    public function __construct(private string $column) {}

    /**
     * Добавить к запросу условие «колонка содержит строку без учёта регистра». В SQLite регистрирует в соединении
     * функцию `fastleo_lower`, на которой построено условие.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function where(EloquentBuilder|QueryBuilder $query, string $column, string $search, string $boolean = 'and'): void
    {
        $connection = ($query instanceof EloquentBuilder ? $query->getQuery() : $query)->getConnection();

        if ($connection instanceof SQLiteConnection) {
            foreach ([$connection->getPdo(), $connection->getReadPdo()] as $pdo) {
                if ($pdo instanceof Sqlite) {
                    $pdo->createFunction('fastleo_lower', fn (mixed $value): mixed => is_string($value) ? mb_strtolower($value) : $value, 1, Sqlite::DETERMINISTIC);
                }
            }
        }

        $query->whereRaw(new self($column), [self::pattern($search)], $boolean);
    }

    /**
     * Шаблон LIKE «содержит строку»: служебные символы LIKE экранируются.
     */
    public static function pattern(string $search): string
    {
        $escape = self::ESCAPE;

        return '%'.str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $search).'%';
    }

    /**
     * SQL условия для грамматики текущей БД.
     */
    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->column);

        if ($grammar instanceof PostgresGrammar) {
            return 'cast('.$column.' as text) ilike ? escape \''.self::ESCAPE.'\'';
        }

        if ($grammar instanceof MySqlGrammar && str_contains($this->column, '->')) {
            return 'lower('.$column.') like lower(?) escape \''.self::ESCAPE.'\'';
        }

        if ($grammar instanceof SQLiteGrammar) {
            return 'fastleo_lower('.$column.') like fastleo_lower(?) escape \''.self::ESCAPE.'\'';
        }

        return $column.' like ? escape \''.self::ESCAPE.'\'';
    }
}
