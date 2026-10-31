<?php

declare(strict_types=1);

use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\Filter;
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
