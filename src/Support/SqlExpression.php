<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Фрагмент SQL, который пакет собрал из проверенных частей (имя колонки проверено регулярным выражением
 * и обёрнуто грамматикой базы). Значения пользователя в него не попадают — они всегда идут привязками.
 */
final readonly class SqlExpression implements Expression
{
    public function __construct(private string $sql) {}

    /**
     * SQL как есть.
     */
    #[\Override]
    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
