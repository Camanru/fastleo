<?php

declare(strict_types=1);

use Fastleo\Support\ContainsExpression;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

it('searches without case in every database, json paths of MySQL included', function (): void {
    $mysql = new MySqlGrammar(DB::connection());

    expect(new ContainsExpression('title')->getValue($mysql))->toBe("`title` like ? escape '!'")
        ->and(new ContainsExpression('title->en')->getValue($mysql))->toBe("lower(json_unquote(json_extract(`title`, '$.\"en\"'))) like lower(?) escape '!'")
        ->and(new ContainsExpression('title')->getValue(new PostgresGrammar(DB::connection())))->toBe("cast(\"title\" as text) ilike ? escape '!'")
        ->and(new ContainsExpression('title')->getValue(new SQLiteGrammar(DB::connection())))->toBe("fastleo_lower(\"title\") like fastleo_lower(?) escape '!'")
        ->and(ContainsExpression::pattern('50%_!'))->toBe('%50!%!_!!%');
});
