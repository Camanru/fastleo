<?php

declare(strict_types=1);

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;
use Fastleo\Filters\BooleanFilter;
use Workbench\App\Models\Page;

it('limits the number of repeater items and becomes required with a minimum', function (): void {
    $field = Repeater::make('faq')->fields([Text::make('question')])->min(1)->max(5);

    expect($field->getMin())->toBe(1)
        ->and($field->getMax())->toBe(5)
        ->and($field->getRules(null)['faq'])->toBe(['required', 'array', 'max:5', 'min:1'])
        ->and($field->display(new Page))->toBeNull();
});

it('names the columns of key value pairs and limits them', function (): void {
    $field = KeyValue::make('specs', 'Характеристики')->keyLabel('Параметр')->valueLabel('Значение')->max(3);

    expect($field->getKeyLabel())->toBe('Параметр')
        ->and($field->getValueLabel())->toBe('Значение')
        ->and($field->getMax())->toBe(3)
        ->and($field->getRules(null)['specs'])->toBe(['nullable', 'array', 'max:3'])
        ->and($field->validationAttributes()['specs.*.key'])->toBe('Характеристики: параметр')
        ->and($field->formValue(null))->toBe([])
        ->and($field->display(new Page))->toBeNull()
        ->and(KeyValue::make('specs')->formValue(null))->toBe([]);
});

it('reads pairs and items saved as a json string', function (): void {
    $page = new Page;
    $page->setRawAttributes(['specs' => '{"Вес":"1 кг"}', 'faq' => '[{"question":"Вопрос"}]']);

    expect(KeyValue::make('specs')->hydrate($page))->toBe([['key' => 'Вес', 'value' => '1 кг']])
        ->and(Repeater::make('faq')->fields([Text::make('question')])->hydrate($page)->first()?->getAttribute('question'))->toBe('Вопрос');
});

it('describes the limits of blocks', function (): void {
    $field = Blocks::make('content')->blocks([Block::make('text')->fields([Text::make('body')])])->min(1)->max(4);

    expect($field->getMin())->toBe(1)
        ->and($field->getMax())->toBe(4)
        ->and($field->getRules(null)['content'])->toBe(['required', 'array', 'max:4', 'min:1'])
        ->and($field->formValue(null))->toBe([])
        ->and($field->display(new Page))->toBeNull()
        ->and(Block::make('photo_gallery')->getLabel())->toBe('Photo Gallery');
});

it('explains an unknown block type', function (): void {
    Blocks::make('content')->schema('video');
})->throws(LogicException::class, 'video');

it('takes its own label', function (): void {
    expect(BooleanFilter::make('is_published')->label('Опубликовано')->getLabel())->toBe('Опубликовано');
});
