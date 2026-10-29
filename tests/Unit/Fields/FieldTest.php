<?php

declare(strict_types=1);

use Fastleo\Fields\Text;
use Illuminate\Support\HtmlString;
use Workbench\App\Models\Category;

it('rejects a name that is not a column name', function (string $name): void {
    Text::make($name);
})->with(['dotted' => ['meta.title'], 'array' => ['tags[]'], 'empty' => [''], 'starts with a digit' => ['1title']])
    ->throws(InvalidArgumentException::class, 'Недопустимое имя поля');

it('makes a label from the name when none is given', function (): void {
    expect(Text::make('meta_title')->getLabel())->toBe('Meta title')
        ->and(Text::make('metaTitle')->getLabel())->toBe('Meta title')
        ->and(Text::make('title', 'Заголовок')->label('Название')->getLabel())->toBe('Название');
});

it('builds nullable or required rules with custom rules last', function (): void {
    $category = Category::factory()->create();
    $field = Text::make('code')->rules(fn (?Category $record): array => ['alpha_dash', 'not_in:'.$record?->id]);

    expect($field->getRules(null))->toBe(['code' => ['nullable', 'string', 'max:255', 'alpha_dash', 'not_in:']])
        ->and($field->required()->getRules($category))->toBe(['code' => ['required', 'string', 'max:255', 'alpha_dash', 'not_in:'.$category->id]]);
});

it('takes the default value only when creating', function (): void {
    $category = Category::factory()->create(['title' => 'Сохранённое']);

    expect(Text::make('title')->default('Новое')->formValue(null))->toBe('Новое')
        ->and(Text::make('title')->default(fn (): string => 'Из замыкания')->formValue(null))->toBe('Из замыкания')
        ->and(Text::make('title')->default('Новое')->formValue($category))->toBe('Сохранённое');
});

it('decides where the field is visible', function (): void {
    $record = Category::factory()->create();

    expect(Text::make('a')->hideOnCreate()->isVisibleInForm(null))->toBeFalse()
        ->and(Text::make('a')->hideOnCreate()->isVisibleInForm($record))->toBeTrue()
        ->and(Text::make('a')->hideOnEdit()->isVisibleInForm($record))->toBeFalse()
        ->and(Text::make('a')->hideOnEdit()->isVisibleInForm(new Category))->toBeTrue()
        ->and(Text::make('a')->onlyInList()->isVisibleInForm(null))->toBeFalse()
        ->and(Text::make('a')->onlyInList()->isVisibleInList())->toBeTrue()
        ->and(Text::make('a')->onlyInList()->isVisibleOnShow($record))->toBeFalse()
        ->and(Text::make('a')->hideInList()->isVisibleInList())->toBeFalse()
        ->and(Text::make('a')->hidden()->isVisibleInList())->toBeFalse()
        ->and(Text::make('a')->hidden(fn (?Category $category): bool => $category instanceof Category)->isVisibleInForm(null))->toBeTrue()
        ->and(Text::make('a')->hidden(fn (?Category $category): bool => $category instanceof Category)->isVisibleInForm($record))->toBeFalse();
});

it('does not write readonly or disabled fields', function (): void {
    expect(Text::make('a')->isWritable())->toBeTrue()
        ->and(Text::make('a')->readonly()->isWritable())->toBeFalse()
        ->and(Text::make('a')->disabled()->isWritable())->toBeFalse();
});

it('formats the list value with a closure', function (): void {
    $record = new Category(['title' => 'Новости']);

    expect(Text::make('title')->formatUsing(fn (mixed $value, Category $category): string => mb_strtoupper((string) $value))->display($record))->toBe('НОВОСТИ')
        ->and(Text::make('title')->formatUsing(fn (): HtmlString => new HtmlString('<b>x</b>'))->display($record))->toBeInstanceOf(HtmlString::class);
});

it('shows arrays as JSON and empty values as nothing', function (): void {
    $record = new Category;
    $record->setRawAttributes(['title' => '', 'code' => null]);

    expect(Text::make('title')->display($record))->toBeNull()
        ->and(Text::make('code')->display($record))->toBeNull();
});

it('limits the grid span to 1–12 columns', function (int $columns): void {
    Text::make('title')->columnSpan($columns);
})->with([0, 13])->throws(InvalidArgumentException::class, 'от 1 до 12');

it('keeps simple settings', function (): void {
    $field = Text::make('title')->help('Подсказка')->placeholder('Введите')->maxLength(20)->sortable()->searchable()->columnSpan(2)->listView('site.cell');

    expect([$field->getHelp(), $field->getPlaceholder(), $field->getMaxLength(), $field->isSortable(), $field->isSearchable(), $field->getColumnSpan(), $field->getListView(), $field->type()])
        ->toBe(['Подсказка', 'Введите', 20, true, true, 2, 'site.cell', 'text']);
});
