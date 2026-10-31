<?php

declare(strict_types=1);

use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Date;
use Fastleo\Fields\Field;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Password;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Url;
use Fastleo\Tests\Fixtures\Enums\PlainPriority;
use Fastleo\Tests\Fixtures\Fields\PlainField;
use Workbench\App\Models\Article;

it('refuses emptyValue() for fields that store several values or a structure', function (Field $field): void {
    expect(fn (): Field => $field->emptyValue(0))->toThrow(LogicException::class, 'emptyValue()');
})->with([
    'belongs to many' => fn (): BelongsToMany => BelongsToMany::make('topics'),
    'blocks' => fn (): Blocks => Blocks::make('content'),
    'key value' => fn (): KeyValue => KeyValue::make('specs'),
    'seo' => fn (): Seo => Seo::make('seo'),
    'has many' => fn (): HasMany => HasMany::make('links'),
    'has one' => fn (): HasOne => HasOne::make('banner'),
]);

it('refuses translations for fields whose value is not a text', function (Field $field): void {
    expect(fn (): Field => $field->translatable())->toThrow(LogicException::class, 'не может быть многоязычным');
})->with([
    'slug' => fn (): Slug => Slug::make('slug'),
    'tel' => fn (): Tel => Tel::make('phone'),
    'url' => fn (): Url => Url::make('site'),
]);

it('allows translations for a textarea and a rich text', function (): void {
    expect(Textarea::make('excerpt')->translatable()->isTranslatable())->toBeTrue()
        ->and(RichText::make('body')->translatable()->isTranslatable())->toBeTrue();
});

it('keeps a password out of the export', function (): void {
    expect(Password::make('password')->isExportable())->toBeFalse()
        ->and(Text::make('title')->isExportable())->toBeTrue();
});

it('takes the name of a case of an enum without values', function (): void {
    expect(Select::make('priority')->default(PlainPriority::High)->formValue(null))->toBe('High')
        ->and(Select::make('priority')->hydrate(new Article(['title' => 'x'])))->toBeNull();
});

it('points a field of the site without a template to the template of its type', function (): void {
    expect(PlainField::make('note')->view())->toBe('fastleo::fields.plain-field');
});

it('shows an array value of a plain field as json', function (): void {
    expect(Text::make('tags')->display(new Article(['tags' => ['а', 'b']])))->toBe('["а","b"]');
});

it('shows a string saved in a date column as a date and leaves out a text that is not a date', function (): void {
    expect(Date::make('title')->display(new Article(['title' => '2026-03-08'])))->toBe('08.03.2026')
        ->and(Date::make('title')->display(new Article(['title' => 'не дата'])))->toBeNull();
});

it('reads the translations of a column that holds a plain string or a json string', function (): void {
    $field = Text::make('title')->translatable();

    expect($field->translations(new Article(['title' => 'Привет'])))->toBe(['ru' => 'Привет'])
        ->and($field->translations(new Article(['title' => '{"ru":"Привет","en":"Hello"}'])))->toBe(['ru' => 'Привет', 'en' => 'Hello']);
});

it('cleans the html of a rich text with the profile of the site', function (): void {
    $plain = RichText::make('body');
    $custom = RichText::make('body')->sanitizeUsing(fn ($config) => $config->allowElement('span', ['class']));
    $html = '<p>Текст <span class="note" onclick="x()">отметка</span></p>';

    expect($plain->sanitize($html))->toBe('<p>Текст отметка</p>')
        ->and($custom->sanitize($html))->toBe('<p>Текст <span class="note">отметка</span></p>');
});

it('cleans every language of a translatable rich text', function (): void {
    $field = RichText::make('body')->translatable();

    expect($field->dehydrate(['ru' => '<p onclick="x()">Привет</p>', 'en' => '<script>x</script>', 'de' => 5]))->toBe(['ru' => '<p>Привет</p>']);
});

it('offers picture extensions for the editor', function (): void {
    expect(RichText::make('body')->imageExtensions())->toContain('jpg', 'png');
});

it('shows a rich text through formatUsing in the list', function (): void {
    $field = RichText::make('body')->formatUsing(fn (mixed $value): string => 'Свой вывод');

    expect($field->listDisplay(new Article(['title' => 'x'])))->toBe('Свой вывод');
});

it('formats a block with a custom closure instead of its own table', function (Field $field): void {
    $field->formatUsing(fn (mixed $value): string => 'Свой вывод');

    expect($field->display(new Article))->toBe('Свой вывод');
})->with([
    'belongs to many' => fn (): BelongsToMany => BelongsToMany::make('topics'),
    'blocks' => fn (): Blocks => Blocks::make('content')->blocks([Block::make('text')]),
    'key value' => fn (): KeyValue => KeyValue::make('specs'),
    'seo' => fn (): Seo => Seo::make('seo'),
    'repeater' => fn (): Repeater => Repeater::make('items')->fields([Text::make('a')]),
    'has many' => fn (): HasMany => HasMany::make('links')->fields([Text::make('title')])->forModel(Article::class),
]);
