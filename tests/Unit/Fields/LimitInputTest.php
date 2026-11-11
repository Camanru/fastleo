<?php

declare(strict_types=1);

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Field;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Messengers\ChatIds;
use Fastleo\Resources\Roles\PermissionMatrix;
use Fastleo\Support\ActionInput;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Workbench\App\Models\Article;

/**
 * Поля-списки: предел `listLimit()` совпадает с `max:N` правил, иначе обрезка молча теряла бы данные поля с большим `max`.
 *
 * @return array<string, Closure(): Field>
 */
dataset('list fields', [
    'repeater' => fn (): Field => Repeater::make('faq')->fields([Text::make('question')])->max(7),
    'blocks' => fn (): Field => Blocks::make('content')->blocks([Block::make('text')->fields([Text::make('body')])])->max(8),
    'has many' => fn (): Field => HasMany::make('links')->fields([Text::make('title')])->max(9)->forModel(Article::class),
    'gallery' => fn (): Field => Gallery::make('photos')->max(10),
    'tags' => fn (): Field => Tags::make('keywords'),
    'belongs to many' => fn (): Field => BelongsToMany::make('topics')->forModel(Article::class),
    'key value' => fn (): Field => KeyValue::make('specs')->max(11),
    'multiple select' => fn (): Field => Select::make('days')->options(['a' => 'A'])->multiple(),
    'permission matrix' => fn (): Field => PermissionMatrix::make('permissions'),
    'chat ids' => fn (): Field => ChatIds::make('chats'),
    'single chat id' => fn (): Field => ChatIds::make('chat')->single(),
]);

it('has a list limit equal to the max rule of the field', function (Field $field): void {
    $max = collect($field->getRules(null)[$field->name])->first(fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'max:'));

    expect($field->listLimit())->toBe((int) substr((string) $max, 4));
})->with('list fields');

it('cuts a list to its limit plus one and keeps the rejection by max', function (Field $field): void {
    $limit = $field->listLimit();
    $item = $field instanceof KeyValue ? ['key' => 'a', 'value' => 'b'] : 'a';
    $cut = $field->limitInput(array_fill(0, 5000, $item));

    expect($cut)->toHaveCount($limit + 1);
})->with('list fields');

it('allows at most 1000 values of a multiple select', function (): void {
    $field = Select::make('days')->options(['a' => 'A'])->multiple();

    expect($field->listLimit())->toBe(Select::MAX_CHOICES)
        ->and(Validator::make(['days' => array_fill(0, 1001, 'a')], $field->getRules(null))->errors()->has('days'))->toBeTrue()
        ->and(Select::make('one')->options(['a' => 'A'])->listLimit())->toBeNull();
});

it('flattens an array in a list of plain values so that no huge nesting is expanded', function (): void {
    $cut = Tags::make('keywords')->limitInput(['a', ['x' => array_fill(0, 100, 'y')], 'b']);

    expect($cut)->toBe(['a', [], 'b']);
});

it('cuts the lists inside the items of Repeater, Blocks and HasMany', function (): void {
    $big = array_fill(0, 5000, 'tag');
    $repeater = Repeater::make('faq')->fields([Text::make('question'), Tags::make('tags')]);
    $blocks = Blocks::make('content')->blocks([Block::make('text')->fields([Tags::make('tags')])]);
    $hasMany = HasMany::make('links')->fields([Text::make('title'), Tags::make('tags')])->forModel(Article::class);
    $hasOne = HasOne::make('banner')->fields([Text::make('title'), Tags::make('tags')])->forModel(Article::class);

    $cutRepeater = $repeater->limitInput([['question' => 'Q', 'tags' => $big, 'junk' => $big, 'id' => 5]]);
    $cutBlocks = $blocks->limitInput([['type' => 'text', 'text' => ['tags' => $big, 'junk' => $big], 'junk' => $big], ['type' => 'nope', 'nope' => ['x' => $big]], 'строка']);
    $cutMany = $hasMany->limitInput([['id' => '3', 'title' => 'T', 'tags' => $big, 'junk' => $big], 'строка']);
    $cutOne = $hasOne->limitInput(['title' => 'T', 'tags' => $big, 'junk' => $big]);

    expect($cutRepeater[0]['tags'])->toHaveCount(101)
        ->and($cutRepeater[0])->toMatchArray(['question' => 'Q', 'junk' => [], 'id' => 5])
        ->and($cutBlocks[0]['text']['tags'])->toHaveCount(101)
        ->and($cutBlocks[0]['text']['junk'])->toBe([])
        ->and($cutBlocks[0]['junk'])->toBe([])
        ->and($cutBlocks[1]['nope'])->toBe([])
        ->and($cutBlocks[2])->toBe('строка')
        ->and($cutMany[0]['tags'])->toHaveCount(101)
        ->and($cutMany[0])->toMatchArray(['id' => '3', 'junk' => []])
        ->and($cutMany[1])->toBe('строка')
        ->and($cutOne['tags'])->toHaveCount(101)
        ->and($cutOne['junk'])->toBe([]);
});

it('keeps only the key and the value of a key value pair', function (): void {
    $cut = KeyValue::make('specs')->limitInput([['key' => 'Вес', 'value' => ['x' => [1]], 'junk' => [1, 2]], 'строка']);

    expect($cut)->toBe([['key' => 'Вес', 'value' => []], 'строка']);
});

it('leaves plain fields and non-arrays as they are', function (): void {
    expect(Text::make('title')->limitInput(['a' => ['b']]))->toBe(['a' => ['b']])
        ->and(Tags::make('keywords')->limitInput('строка'))->toBe('строка')
        ->and(Repeater::make('faq')->fields([Text::make('q')])->limitInput('строка'))->toBe('строка')
        ->and(Blocks::make('content')->blocks([Block::make('text')->fields([Text::make('body')])])->limitInput('строка'))->toBe('строка')
        ->and(HasMany::make('links')->fields([Text::make('t')])->forModel(Article::class)->limitInput('строка'))->toBe('строка')
        ->and(KeyValue::make('specs')->limitInput('строка'))->toBe('строка');
});

it('rejects the elements that the cut turned into empty arrays', function (): void {
    $tags = Tags::make('keywords');
    $gallery = Gallery::make('photos');

    expect(Validator::make(['keywords' => $tags->limitInput([['x' => 1]])], $tags->getRules(null))->fails())->toBeTrue()
        ->and(Validator::make(['photos' => $gallery->limitInput([['x' => 1]])], $gallery->getRules(null))->fails())->toBeTrue();
});

it('cuts a long list in the mini form of an action', function (): void {
    $action = new class extends Action
    {
        public function fields(): array
        {
            return [Tags::make('tags')];
        }

        public function handle(Collection $records, array $data): ActionResult
        {
            return ActionResult::message('ok');
        }
    };

    $validator = ActionInput::validator($action->forResource(Fastleo\Fastleo::resources()->find('articles')), ['data' => ['tags' => array_fill(0, 5000, 'tag')]]);

    expect($validator->getData()['data']['tags'])->toHaveCount(101)
        ->and($validator->fails())->toBeTrue();
});
