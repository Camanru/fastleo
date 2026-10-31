<?php

declare(strict_types=1);

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Field;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Actions\MarkFeatured;
use Fastleo\Tests\Fixtures\Actions\RenameArticles;
use Illuminate\Database\Eloquent\Collection;
use Workbench\App\Models\Article;

/**
 * Ресурс статей с заданными действиями.
 *
 * @param  list<Action>  $actions
 */
function resourceWithActions(array $actions): Resource
{
    return new class($actions) extends Resource
    {
        protected static string $model = Article::class;

        /**
         * @param  list<Action>  $items
         */
        public function __construct(private readonly array $items) {}

        public function fields(): array
        {
            return [Text::make('title')];
        }

        public function actions(): array
        {
            return $this->items;
        }
    };
}

it('names an action after its class and puts the built-in ones first', function (): void {
    $actions = resourceWithActions([new MarkFeatured, new RenameArticles])->listActions();

    expect(array_keys($actions))->toBe(['delete', 'restore', 'force-delete', 'mark-featured', 'rename-articles'])
        ->and($actions['rename-articles']->getLabel())->toBe('Дописать к заголовку')
        ->and($actions['mark-featured']->clientOptions())->toBe(['confirm' => 'Показать на главной?', 'button' => 'На главную', 'danger' => false, 'form' => false]);
});

it('refuses an action whose key is taken by a built-in one, even where that one is off', function (string $key): void {
    $taken = new class($key) extends Action
    {
        public function __construct(string $key)
        {
            $this->key = $key;
        }

        public function handle(Collection $records, array $data): ActionResult
        {
            return ActionResult::success('');
        }
    };

    resourceWithActions([$taken])->listActions();
})->with(['delete', 'mark-read', 'mark-unread'])->throws(LogicException::class, 'delete, restore, force-delete, mark-read, mark-unread заняты в любом разделе');

it('refuses fields that do not work in a mini form', function (Field $field): void {
    $action = new class($field) extends Action
    {
        protected ?string $key = 'move';

        public function __construct(private readonly Field $field) {}

        public function fields(): array
        {
            return [$this->field];
        }

        public function handle(Collection $records, array $data): ActionResult
        {
            return ActionResult::success('');
        }
    };

    resourceWithActions([$action])->listActions()['move']->schema();
})->throws(LogicException::class, 'нельзя использовать в мини-форме')->with([
    'relation' => fn (): BelongsTo => BelongsTo::make('category_id'),
    'json field' => fn (): KeyValue => KeyValue::make('specs'),
    'translatable' => fn (): Text => Text::make('note')->translatable(),
]);

it('explains an action used outside a resource', function (): void {
    new MarkFeatured()->schema();
})->throws(LogicException::class, 'не привязано к ресурсу');
