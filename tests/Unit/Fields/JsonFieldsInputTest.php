<?php

declare(strict_types=1);

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\Image;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Text;
use Fastleo\Files\GenerateImageVariants;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Workbench\App\Models\Article;
use Workbench\App\Models\Page;

/**
 * Блоки с одним типом «картинка», у картинки задан размер вариантов.
 */
function imageBlocks(): Blocks
{
    return Blocks::make('content')->blocks([Block::make('image')->fields([Text::make('alt'), Image::make('src')->directory('pages')->variants('card')])]);
}

describe('junk in the request', function (): void {
    it('skips blocks that are not arrays or have an unknown or broken type', function (): void {
        $field = Blocks::make('content')->blocks([Block::make('text')->fields([Text::make('body')])]);

        expect($field->dehydrate([
            'мусор',
            ['type' => 'video'],
            ['type' => ['text']],
            ['type' => 'text', 'text' => ['body' => ' Текст ', 'evil' => 'x']],
        ]))->toBe([['type' => 'text', 'data' => ['body' => 'Текст']]]);
    });

    it('skips repeater items that are not arrays and drops the items nobody filled', function (): void {
        $field = Repeater::make('faq')->fields([Text::make('question'), Text::make('answer')]);

        expect($field->dehydrate(['мусор', ['question' => '', 'answer' => null], ['question' => 'Вопрос'], 5]))->toBe([['question' => 'Вопрос']]);
    });

    it('skips key value pairs that are not arrays', function (): void {
        expect(KeyValue::make('specs')->dehydrate(['мусор', ['key' => ' Вес ', 'value' => '1 кг'], 7]))->toBe(['Вес' => '1 кг']);
    });

    it('does not run the name check of a pair on a name that is not a text', function (): void {
        $validator = Validator::make(
            ['specs' => [['key' => ['Вес'], 'value' => '1']]],
            KeyValue::make('specs')->getRules(null),
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->keys())->toBe(['specs.0.key']);
    });

    it('skips nested blocks that are not arrays', function (): void {
        $many = HasMany::make('links')->fields([Text::make('title')])->forModel(Article::class);
        $one = HasOne::make('banner')->fields([Text::make('title')])->forModel(Article::class);

        expect($many->dehydrate(['мусор', ['title' => 'Прайс']]))->toBe([['id' => null, 'values' => ['title' => 'Прайс']]])
            ->and($one->dehydrate('мусор'))->toBe([]);
    });

    it('writes nothing for broken nested blocks and for a one-to-one block that arrived empty', function (): void {
        $article = Article::factory()->create();
        $many = HasMany::make('links')->fields([Text::make('title')])->forModel(Article::class);
        $one = HasOne::make('banner')->fields([Text::make('title')])->forModel(Article::class);

        $many->afterSave($article, ['мусор', ['id' => null, 'values' => 'не список'], ['id' => null, 'values' => ['title' => '']]]);
        $one->afterSave($article, []);

        expect($article->links()->count())->toBe(0)
            ->and($article->banner()->count())->toBe(0);
    });
});

describe('saving a record that was not changed', function (): void {
    it('queues no variants for the pictures of blocks when the column stayed as it was', function (): void {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/pages/a.jpg', 'x');
        config(['fastleo.images.presets' => ['card' => ['widths' => [400], 'ratio' => null]]]);
        $field = imageBlocks();
        $page = Page::factory()->create();
        $value = [['type' => 'image', 'data' => ['src' => 'uploads/pages/a.jpg']]];
        Queue::fake();

        $field->afterSave(Page::query()->findOrFail($page->id), $value);
        Queue::assertNothingPushed();

        $field->afterSave($page, $value);
        Queue::assertPushed(GenerateImageVariants::class, 1);
    });

    it('does nothing after saving when the seo column stayed as it was', function (): void {
        Queue::fake();
        $article = Article::factory()->create();

        Seo::make('seo')->afterSave(Article::query()->findOrFail($article->id), ['image' => 'uploads/seo/a.jpg']);

        Queue::assertNothingPushed();
    });
});

describe('reading', function (): void {
    it('reads key value pairs stored as a json string in a column without a cast when showing them', function (): void {
        $article = new Article(['title' => '{"Вес":"1 кг"}']);

        expect((string) KeyValue::make('title')->display($article))->toContain('Вес')->toContain('1 кг')
            ->and(KeyValue::make('title')->display(new Article(['title' => 'не json'])))->toBeNull();
    });
});
