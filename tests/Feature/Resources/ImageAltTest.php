<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Image;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;
use Fastleo\Support\FormSchema;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('adds the alt column as a field of the schema right after the picture', function (): void {
    $schema = new FormSchema([Image::make('cover')->alt('cover_alt'), Text::make('title')]);

    expect(array_map(fn (Field $field): string => $field->name, $schema->fields()))->toBe(['cover', 'cover_alt', 'title'])
        ->and($schema->field('cover_alt'))->toBeInstanceOf(Text::class)
        ->and($schema->field('cover_alt')?->getLabel())->toBe('Описание картинки (alt)')
        ->and($schema->field('cover_alt')?->isVisibleInList())->toBeFalse();
});

it('takes the label of the alt and the access of the picture', function (): void {
    $schema = new FormSchema([Image::make('cover')->alt('cover_alt', 'Подпись')->readonly()->visibleWhen('type', 'news')]);
    $alt = $schema->field('cover_alt');

    expect($alt?->getLabel())->toBe('Подпись')
        ->and($alt?->isReadonly())->toBeTrue()
        ->and($alt?->getVisibleWhen())->toBe(['field' => 'type', 'values' => ['news']]);
});

it('refuses a column of the alt that another field already uses', function (): void {
    new FormSchema([Image::make('cover')->alt('title'), Text::make('title')]);
})->throws(InvalidArgumentException::class);

it('saves the alt together with the picture and shows it under the picture', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData(['cover' => 'uploads/articles/a.jpg', 'cover_alt' => ' Фасад магазина ']))->assertSessionHasNoErrors();

    expect(Article::query()->firstOrFail()->only('cover', 'cover_alt'))->toBe(['cover' => 'uploads/articles/a.jpg', 'cover_alt' => 'Фасад магазина']);

    $this->get(route('fastleo.resources.edit', ['articles', Article::query()->firstOrFail()->id]))
        ->assertOk()
        ->assertSee('name="cover_alt"', false)
        ->assertSee('value="Фасад магазина"', false)
        ->assertSee('Описание картинки (alt)');
});

it('shows the error of the picture under the picture, not under its alt', function (): void {
    $this->from(route('fastleo.resources.create', 'articles'))
        ->post(route('fastleo.resources.store', 'articles'), articleData(['cover' => '../.env', 'cover_alt' => 'Фасад']))
        ->assertSessionHasErrors('cover');

    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertSeeInOrder(['id="field-cover-error"', 'name="cover_alt"'], false);
});

it('limits the length of the alt', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData(['cover_alt' => str_repeat('а', 256)]))->assertSessionHasErrors('cover_alt');
});

it('saves the alt of a picture inside a repeater item as its neighbour key', function (): void {
    $repeater = Repeater::make('gallery')->fields([Image::make('src')->alt('alt'), Text::make('title')]);

    expect($repeater->dehydrate([['src' => 'uploads/a.jpg', 'alt' => 'Вход', 'title' => 'Первая']]))->toBe([['src' => 'uploads/a.jpg', 'alt' => 'Вход', 'title' => 'Первая']])
        ->and(array_keys($repeater->getRules(null)))->toContain('gallery.*.alt');
});

it('tells the alt column to the agent', function (): void {
    Artisan::call('fastleo:inspect articles --json');
    $fields = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['fields'])->keyBy('name');

    expect($fields['cover']['alt'])->toBe('cover_alt')
        ->and($fields)->toHaveKey('cover_alt');
});
