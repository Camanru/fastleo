<?php

declare(strict_types=1);

use Fastleo\Fields\Text;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\BelongsToManyFilter;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\Filter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Ресурс статей с одним полем и заданными фильтрами.
 *
 * @param  list<Filter>  $filters
 */
function resourceWithFilters(array $filters): Resource
{
    return new class($filters) extends Resource
    {
        protected static string $model = Article::class;

        /**
         * @param  list<Filter>  $items
         */
        public function __construct(private readonly array $items) {}

        public function fields(): array
        {
            return [Text::make('title', 'Заголовок')];
        }

        public function filters(): array
        {
            return $this->items;
        }
    };
}

it('takes its label from the field of the same name, otherwise from the name', function (): void {
    $filters = resourceWithFilters([SelectFilter::make('title')->options([]), BooleanFilter::make('is_published')])->listFilters();

    expect($filters['title']->getLabel())->toBe('Заголовок')
        ->and($filters['is_published']->getLabel())->toBe('Is Published')
        ->and(BooleanFilter::make('is_published', 'Опубликовано')->getLabel())->toBe('Опубликовано');
});

it('takes the options of an enum', function (): void {
    expect(SelectFilter::make('status')->options(ArticleStatus::class)->getOptions())
        ->toBe(['draft' => 'Черновик', 'review' => 'На проверке', 'published' => 'Опубликовано']);
});

it('explains a select filter without options', function (): void {
    resourceWithFilters([SelectFilter::make('title')])->listFilters()['title']->getOptions();
})->throws(LogicException::class, 'добавьте в fields() ресурса поле Select');

it('explains a relation filter without a relation field', function (): void {
    resourceWithFilters([BelongsToFilter::make('category_id')])->listFilters()['category_id']->value('1');
})->throws(LogicException::class, "BelongsTo::make('category_id')");

it('explains a many-to-many filter without a relation field', function (): void {
    resourceWithFilters([BelongsToManyFilter::make('topics')])->listFilters()['topics']->value('1');
})->throws(LogicException::class, "BelongsToMany::make('topics')");

it('explains a filter described twice', function (): void {
    resourceWithFilters([BooleanFilter::make('is_published'), BooleanFilter::make('is_published')])->listFilters();
})->throws(LogicException::class, 'описан в ресурсе');

it('rejects a name that is not a column name', function (): void {
    BooleanFilter::make('is_published; drop');
})->throws(InvalidArgumentException::class);
