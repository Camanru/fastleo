<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;

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
