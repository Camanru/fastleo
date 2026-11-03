<?php

declare(strict_types=1);

use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\Filter;
use Fastleo\Filters\NumberRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Article;

it('takes the options of a select filter from a closure', function (): void {
    $filter = SelectFilter::make('type')->options(fn (): array => ['news' => 'Новости', 'blog' => 'Блог']);

    expect($filter->getOptions())->toBe(['news' => 'Новости', 'blog' => 'Блог'])
        ->and($filter->value('blog'))->toBe('blog')
        ->and($filter->value('hacked'))->toBeNull();
});

it('does not touch the query when a date range filter gets something else than a range', function (): void {
    $query = Article::query();

    DateRangeFilter::make('published_on')->apply($query, 'yesterday');
    DateRangeFilter::make('published_on')->apply($query, null);

    expect($query->getQuery()->wheres)->toBe([]);
});

it('does not touch the query when a number range filter gets something else than a range', function (): void {
    $query = Article::query();

    NumberRangeFilter::make('views')->apply($query, '100');
    NumberRangeFilter::make('views')->apply($query, null);

    expect($query->getQuery()->wheres)->toBe([]);
});

it('knows the range of values of an integer column type', function (string $typeName, string $type, ?array $range): void {
    expect(NumberRangeFilter::rangeOf($typeName, $type))->toBe($range);
})->with([
    'postgres integer' => ['int4', 'integer', [-2147483648, 2147483647]],
    'mysql int' => ['int', 'int', [-2147483648, 2147483647]],
    'mysql unsigned int' => ['int', 'int unsigned', [0, 4294967295]],
    'smallint' => ['int2', 'smallint', [-32768, 32767]],
    'tinyint' => ['tinyint', 'tinyint', [-128, 127]],
    'unsigned tinyint' => ['tinyint', 'tinyint unsigned', [0, 255]],
    'mediumint' => ['mediumint', 'mediumint', [-8388608, 8388607]],
    'sqlite integer' => ['integer', 'integer', [PHP_INT_MIN, PHP_INT_MAX]],
    'unsigned bigint' => ['bigint', 'bigint unsigned', [0, PHP_INT_MAX]],
    'decimal' => ['numeric', 'numeric(10,2)', null],
    'text' => ['varchar', 'varchar(255)', null],
]);

it('keeps a bound inside the values of the column type and matches nothing when the range lies outside it', function (): void {
    foreach ([0 => 'Ноль', 100 => 'Сто', 5000 => 'Пять тысяч'] as $views => $title) {
        Article::factory()->create(['title' => $title, 'views' => $views]);
    }

    // SQLite хранит любое целое в 64 битах, поэтому узкий тип колонки подставляется.
    $filter = new class('views') extends NumberRangeFilter
    {
        protected function integerRange(Builder $query): array
        {
            return [-128, 127];
        }
    };
    $titles = function (array $range) use ($filter): array {
        $query = Article::query();
        $filter->apply($query, $filter->value($range));

        return $query->orderBy('views')->pluck('title')->all();
    };

    expect($titles(['from' => '1000']))->toBe([])
        ->and($titles(['to' => '-1000']))->toBe([])
        ->and($titles(['from' => '-9999999999', 'to' => '9999999999']))->toBe(['Ноль', 'Сто'])
        ->and($titles(['from' => '99.5', 'to' => '9999999999']))->toBe(['Сто'])
        ->and($titles(['to' => '100.9']))->toBe(['Ноль', 'Сто']);
});

it('tells a custom filter that asks for the resource before it is put into filters()', function (): void {
    $filter = new class('custom') extends Filter
    {
        public function value(mixed $input): mixed
        {
            return $input;
        }

        public function view(): string
        {
            return 'fastleo::filters.boolean';
        }

        public function boundResource(): Resource
        {
            return $this->resource();
        }

        protected function query(Builder $query, mixed $value): void
        {
            //
        }
    };

    $filter->boundResource();
})->throws(LogicException::class, 'не привязан к ресурсу');
