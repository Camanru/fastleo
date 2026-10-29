<?php

declare(strict_types=1);

use Fastleo\Fields\Field;
use Fastleo\Fields\Text;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Support\FormSchema;
use Workbench\App\Models\Category;

it('collects fields from nested containers in order', function (): void {
    $schema = new FormSchema([
        Text::make('a'),
        Tabs::make([
            Tab::make('Первая', [Grid::make(2, [Text::make('b'), Section::make('С', [Text::make('c')])])]),
        ]),
        Aside::make([Text::make('d')]),
    ], Category::class);

    expect(array_map(fn (Field $field): string => $field->name, $schema->fields()))->toBe(['a', 'b', 'c', 'd'])
        ->and($schema->field('c')?->name)->toBe('c')
        ->and($schema->field('zzz'))->toBeNull();
});

it('rejects two fields with one name', function (): void {
    new FormSchema([Text::make('a'), Section::make('С', [Text::make('a')])], Category::class);
})->throws(InvalidArgumentException::class, 'Поле «a» описано в ресурсе дважды.');

it('accepts only tabs inside Tabs', function (): void {
    Tabs::make([Text::make('a')]);
})->throws(TypeError::class, Tab::class);

it('limits grid columns to 1–12', function (): void {
    Grid::make(13, []);
})->throws(InvalidArgumentException::class, 'от 1 до 12');

it('skips containers without visible fields', function (): void {
    $tabs = Tabs::make([
        Tab::make('Пустая', [Text::make('a')->hideOnCreate()]),
        Tab::make('Полная', [Text::make('b')]),
    ]);

    expect(array_map(fn (Tab $tab): string => $tab->label, $tabs->visibleTabs(null)))->toBe(['Полная'])
        ->and(Section::make('С', [Text::make('a')->hidden()])->hasVisibleFields(null))->toBeFalse();
});

it('makes a collapsed section collapsible', function (): void {
    $section = Section::make('С', [])->description('Пояснение')->collapsed();

    expect([$section->isCollapsible(), $section->isCollapsed(), $section->getDescription()])->toBe([true, true, 'Пояснение']);
});
