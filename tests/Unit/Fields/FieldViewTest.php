<?php

declare(strict_types=1);

use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Code;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Email;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Field;
use Fastleo\Fields\File;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\HasOne;
use Fastleo\Fields\Image;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Radio;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Secret;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Fields\Url;
use Fastleo\Tests\Fixtures\Fields\SiteEmail;
use Fastleo\Tests\Fixtures\Fields\SiteImage;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\View;

it('gives every package field the template of its nearest package class', function (Field $field, string $view): void {
    expect($field->view())->toBe('fastleo::fields.'.$view);
})->with([
    'text' => [fn (): Text => Text::make('title'), 'text'],
    'email' => [fn (): Email => Email::make('email'), 'text'],
    'url' => [fn (): Url => Url::make('site'), 'text'],
    'tel' => [fn (): Tel => Tel::make('phone'), 'text'],
    'slug' => [fn (): Slug => Slug::make('slug'), 'slug'],
    'file' => [fn (): File => File::make('price'), 'file'],
    'image' => [fn (): Image => Image::make('photo'), 'file'],
    'gallery' => [fn (): Gallery => Gallery::make('photos'), 'gallery'],
    'enum' => [fn (): Enum => Enum::make('status'), 'select'],
    'radio' => [fn (): Radio => Radio::make('kind'), 'radio'],
    'belongs to' => [fn (): BelongsTo => BelongsTo::make('category_id'), 'combobox'],
    'belongs to many' => [fn (): BelongsToMany => BelongsToMany::make('topics'), 'combobox'],
    'has many' => [fn (): HasMany => HasMany::make('rows'), 'nested'],
    'has one' => [fn (): HasOne => HasOne::make('profile'), 'nested'],
    'key value' => [fn (): KeyValue => KeyValue::make('specs'), 'key-value'],
    'repeater' => [fn (): Repeater => Repeater::make('items'), 'repeater'],
    'seo' => [fn (): Seo => Seo::make('seo'), 'seo'],
    'blocks' => [fn (): Blocks => Blocks::make('content'), 'blocks'],
    'toggle' => [fn (): Toggle => Toggle::make('is_active'), 'toggle'],
    'code' => [fn (): Code => Code::make('html'), 'code'],
    'secret' => [fn (): Secret => Secret::make('token'), 'secret'],
    'date time' => [fn (): DateTime => DateTime::make('published_at'), 'date-time'],
]);

it('takes the site template of a subclass of a field with a renamed package template', function (): void {
    $dir = sys_get_temp_dir().'/fastleo-view-'.getmypid().'-'.uniqid();
    Filesystem::ensureDirectoryExists($dir.'/fastleo/fields');
    View::getFinder()->prependLocation($dir);

    try {
        expect(new SiteImage('photo')->view())->toBe('fastleo::fields.file')
            ->and(new SiteEmail('email')->view())->toBe('fastleo::fields.text');

        Filesystem::put($dir.'/fastleo/fields/site-image.blade.php', 'site image');
        Filesystem::put($dir.'/fastleo/fields/site-email.blade.php', 'site email');

        expect(new SiteImage('photo')->view())->toBe('fastleo.fields.site-image')
            ->and(view(new SiteImage('photo')->view())->render())->toBe('site image')
            ->and(new SiteEmail('email')->view())->toBe('fastleo.fields.site-email');
    } finally {
        Filesystem::deleteDirectory($dir);
    }
});
