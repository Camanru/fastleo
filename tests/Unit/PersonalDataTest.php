<?php

declare(strict_types=1);

use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Email;
use Fastleo\Fields\Field;
use Fastleo\Fields\Image;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Support\PersonalData;

it('finds fields that keep an e-mail or a phone', function (Field $field): void {
    expect(PersonalData::find([Text::make('title'), $field]))->toBe($field->name);
})->with([
    'Email type' => fn (): Email => Email::make('contact'),
    'Tel type' => fn (): Tel => Tel::make('contact'),
    'phone' => fn (): Text => Text::make('phone'),
    'with a prefix' => fn (): Text => Text::make('client_phone'),
    'with a digit' => fn (): Text => Text::make('phone2'),
    'plural' => fn (): Text => Text::make('phones'),
    'camel case' => fn (): Text => Text::make('phoneNumber'),
    'cellphone' => fn (): Text => Text::make('cellphone'),
    'whatsapp' => fn (): Text => Text::make('whatsapp'),
    'e-mail' => fn (): Text => Text::make('e_mail'),
    'emails' => fn (): Text => Text::make('emails'),
    'telephone' => fn (): Text => Text::make('telephone'),
]);

it('does not take fields that only look like contacts', function (Field $field): void {
    expect(PersonalData::find([Text::make('title'), $field]))->toBeNull();
})->with([
    'flag to show the phone' => fn (): Toggle => Toggle::make('show_phone'),
    'checkbox' => fn (): Checkbox => Checkbox::make('phone_visible'),
    'e-mail sent flag' => fn (): Toggle => Toggle::make('email_sent'),
    'banner for mobiles' => fn (): Image => Image::make('image_mobile'),
    'mobile menu' => fn (): Text => Text::make('mobile_menu'),
    'date of the e-mail' => fn (): DateTime => DateTime::make('email_at'),
    'telegram' => fn (): Text => Text::make('telegram'),
    'hotel' => fn (): Text => Text::make('hotel'),
]);

it('looks into repeaters and blocks and names the way to the field', function (): void {
    $repeater = Repeater::make('contacts')->fields([Text::make('label'), Tel::make('number')]);
    $blocks = Blocks::make('content')->blocks([Block::make('call')->fields([Email::make('address')])]);

    expect(PersonalData::find([$repeater]))->toBe('contacts.number')
        ->and(PersonalData::find([$blocks]))->toBe('content.address');
});

it('takes a repeater named after phones even when the inner field has a neutral name', function (): void {
    expect(PersonalData::find([Repeater::make('phones')->fields([Text::make('number')])]))->toBe('phones');
});
