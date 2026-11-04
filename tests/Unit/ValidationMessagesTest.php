<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;
use Fastleo\Support\VideoLinkRule;
use Illuminate\Validation\Rule;

it('gives the text of the package for a rule the site does not translate', function (): void {
    expect(ValidationMessages::line('exists'))->toBe('Выбрано недопустимое значение поля «:attribute».')
        ->and(ValidationMessages::all())->toHaveKey('exists');
});

it('prefers the text of the site to the text of the package', function (): void {
    resolve('translator')->addLines(['validation.exists' => 'Текст сайта'], Fastleo::LOCALE);

    expect(ValidationMessages::line('exists'))->toBe('Текст сайта')
        ->and(ValidationMessages::all())->not->toHaveKey('exists');
});

it('names the rule itself when nobody has a text for it', function (): void {
    expect(ValidationMessages::line('no_such_rule'))->toBe('no_such_rule');
});

it('leaves out only the size variants the site already translates', function (): void {
    resolve('translator')->addLines(['validation.max.string' => 'Слишком длинно'], Fastleo::LOCALE);

    $max = ValidationMessages::all()['max'];

    expect($max)->toBeArray()->not->toHaveKey('string')->toHaveKeys(['numeric', 'array', 'file']);
});

it('keeps the text of a rule object on a field named like a rule', function (string $field, mixed $rule): void {
    $rules = [$field => ['required', $rule]];

    $validator = validator([$field => 'не ссылка'], $rules, ValidationMessages::all($rules));

    expect($validator->errors()->first($field))->not->toBe(ValidationMessages::line($field))
        ->and($validator->errors()->first($field))->toBe(__('fastleo::resources.video_link', [], Fastleo::LOCALE));
})->with([
    'url' => ['url', new VideoLinkRule],
    'email' => ['email', new VideoLinkRule],
]);

it('still gives the text of the rule itself to a field named like that rule', function (): void {
    $rules = ['url' => ['required', 'url'], 'site' => ['required', 'url']];

    $validator = validator(['url' => 'не ссылка', 'site' => 'не ссылка'], $rules, ValidationMessages::all($rules));

    expect($validator->errors()->first('url'))->toBe('Поле «url» должно быть адресом (https://...).')
        ->and($validator->errors()->first('site'))->toBe('Поле «site» должно быть адресом (https://...).');
});

it('gives the text of the rule to a rule object on a field named like that rule', function (): void {
    $rules = ['date' => ['required', Rule::date()], 'site' => ['required', Rule::in(['a'])], 'in' => ['nullable']];

    $validator = validator(['date' => 'не дата', 'site' => 'b'], $rules, ValidationMessages::all($rules));

    expect($validator->errors()->first('date'))->toBe('Поле «date» должно быть датой.')
        ->and($validator->errors()->first('site'))->toBe('Выбрано недопустимое значение поля «site».');
});
