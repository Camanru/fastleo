<?php

declare(strict_types=1);

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Number;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Filters\BooleanFilter;
use Illuminate\Support\Facades\Validator;
use Workbench\App\Models\Page;

it('limits the number of repeater items and becomes required with a minimum', function (): void {
    $field = Repeater::make('faq')->fields([Text::make('question')])->min(1)->max(5);

    expect($field->getMin())->toBe(1)
        ->and($field->getMax())->toBe(5)
        ->and($field->getRules(null)['faq'])->toBe(['required', 'array', 'max:5', 'min:1'])
        ->and($field->display(new Page()))->toBeNull();
});

it('names the columns of key value pairs and limits them', function (): void {
    $field = KeyValue::make('specs', 'Характеристики')->keyLabel('Параметр')->valueLabel('Значение')->max(3);

    expect($field->getKeyLabel())->toBe('Параметр')
        ->and($field->getValueLabel())->toBe('Значение')
        ->and($field->getMax())->toBe(3)
        ->and($field->getRules(null)['specs'])->toBe(['nullable', 'array', 'max:3'])
        ->and($field->validationAttributes()['specs.*.key'])->toBe('Характеристики: параметр')
        ->and($field->formValue(null))->toBe([])
        ->and($field->display(new Page()))->toBeNull()
        ->and(KeyValue::make('specs')->formValue(null))->toBe([]);
});

it('reads pairs and items saved as a json string', function (): void {
    $page = new Page();
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
        ->and($field->display(new Page()))->toBeNull()
        ->and(Block::make('photo_gallery')->getLabel())->toBe('Photo Gallery');
});

it('explains an unknown block type', function (): void {
    Blocks::make('content')->schema('video');
})->throws(LogicException::class, 'video');

it('takes its own label', function (): void {
    expect(BooleanFilter::make('is_published')->label('Опубликовано')->getLabel())->toBe('Опубликовано');
});

it('keeps visibleWhen with a percent sign working inside a list', function (): void {
    $rules = Repeater::make('rows')->fields([
        Select::make('unit')->options(['%' => 'Процент', 'pcs' => 'Штуки']),
        Text::make('value')->visibleWhen('unit', '%'),
    ])->getRules(null);

    $validated = Validator::make(['rows' => [['unit' => '%', 'value' => 'a'], ['unit' => 'pcs', 'value' => 'b']]], $rules)->validated();

    expect($validated)->toBe(['rows' => [['unit' => '%', 'value' => 'a'], ['unit' => 'pcs']]]);
});

it('refuses an asterisk in a visibleWhen value inside a list but not outside', function (): void {
    expect(Text::make('value')->visibleWhen('unit', 'a*b')->getRules(null))->toHaveKey('value');

    Repeater::make('rows')->fields([Select::make('unit')->options(['a*b' => 'A']), Text::make('value')->visibleWhen('unit', 'a*b')])->getRules(null);
})->throws(LogicException::class, '«*»');

it('does not compare every pair with every other one when there are more pairs than allowed', function (): void {
    $pairs = array_fill(0, 6, ['key' => 'Вес', 'value' => '1']);
    $validator = Validator::make(['specs' => $pairs], KeyValue::make('specs')->max(5)->getRules(null));

    expect($validator->errors()->keys())->toBe(['specs']);
});

it('does not take an item whose only value is the integer zero for an untouched one', function (): void {
    $field = Number::make('qty');

    expect($field->isUntouched(0))->toBeFalse()
        ->and($field->isUntouched('0'))->toBeFalse()
        ->and($field->isUntouched(null))->toBeTrue()
        ->and($field->isUntouched(''))->toBeTrue()
        ->and(Tags::make('tags')->isUntouched(['a']))->toBeFalse();
});

it('refuses a visibleWhen with no values', function (): void {
    Text::make('promo')->visibleWhen('type', []);
})->throws(InvalidArgumentException::class, 'список значений пуст');

it('keeps blocks of a type that was removed from the resource when the record is saved', function (): void {
    $field = Blocks::make('content')->blocks([Block::make('text')->fields([Text::make('body')])]);
    $page = new Page();
    $page->setRawAttributes(['content' => json_encode([
        ['type' => 'text', 'data' => ['body' => 'Старый']],
        ['type' => 'video', 'data' => ['url' => 'https://example.com/v']],
        ['data' => ['broken' => true]],
    ])]);

    $field->fill($page, $field->dehydrate([['type' => 'text', 'text' => ['body' => 'Новый']]]));

    expect($page->content)->toBe([
        ['type' => 'text', 'data' => ['body' => 'Новый']],
        ['type' => 'video', 'data' => ['url' => 'https://example.com/v']],
        ['data' => ['broken' => true]],
    ]);
});
