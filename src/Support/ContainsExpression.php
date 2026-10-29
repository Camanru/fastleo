<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Условие «колонка содержит подстроку без учёта регистра» для `whereRaw()`:
 * `col like ? escape '!'` (PostgreSQL — `cast(col as text) ilike ? escape '!'`; JSON-путь `col->ru` в MySQL —
 * `lower(...) like lower(?)`: значение из JSON там сравнивается с учётом регистра).
 *
 * Имя колонки экранирует грамматика БД; значение передаётся привязкой (`?`).
 * Шаблон для привязки готовит `pattern()`: `%` и `_` в строке поиска ищутся как обычные символы.
 *
 * ```php
 * $query->whereRaw(new ContainsExpression('title'), [ContainsExpression::pattern('50%')]);
 * ```
 */
final readonly class ContainsExpression implements Expression
{
    /** Символ экранирования в LIKE: одинаково работает в SQLite, MySQL и PostgreSQL. */
    public const string ESCAPE = '!';

    public function __construct(private string $column) {}

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

        return $column.' like ? escape \''.self::ESCAPE.'\'';
    }
}
