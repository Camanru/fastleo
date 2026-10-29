<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Color;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Email;
use Fastleo\Fields\Hidden;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Time;
use Fastleo\Fields\Toggle;
use Fastleo\Fields\Url;
use Illuminate\Contracts\Support\Htmlable;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * HTML значения поля в списке.
 */
function displayed(mixed $display): string
{
    return $display instanceof Htmlable ? $display->toHtml() : (string) $display;
}

describe('text-like fields', function (): void {
    it('uses the matching input type and rules', function (): void {
        expect(Email::make('email')->inputType())->toBe('email')
            ->and(Email::make('email')->getRules(null)['email'])->toContain('email')
            ->and(Tel::make('phone')->getRules(null)['phone'])->toBe(['nullable', 'string', 'max:32'])
            ->and(Url::make('site')->getRules(null)['site'])->toContain('url:http,https');
    });

    it('shows a link only for an http address and escapes it', function (): void {
        $link = new Article(['source_url' => 'https://example.com/?a="x"']);
        $script = new Article(['source_url' => 'javascript:alert(1)']);

        expect(displayed(Url::make('source_url')->display($link)))->toBe('<a href="https://example.com/?a=&quot;x&quot;" target="_blank" rel="noopener noreferrer" class="text-primary-text hover:underline">https://example.com/?a=&quot;x&quot;</a>')
            ->and(Url::make('source_url')->display($script))->toBe('javascript:alert(1)');
    });

    it('hides a textarea in the list and shortens it when shown', function (): void {
        $record = new Article(['excerpt' => str_repeat('а', 150)]);

        expect(Textarea::make('excerpt')->isVisibleInList())->toBeFalse()
            ->and(Textarea::make('excerpt')->showInList()->isVisibleInList())->toBeTrue()
            ->and(mb_strlen((string) Textarea::make('excerpt')->listDisplay($record)))->toBe(103)
            ->and(mb_strlen((string) Textarea::make('excerpt')->display($record)))->toBe(150)
            ->and(Textarea::make('excerpt')->maxLength(500)->rows(0)->getRules(null)['excerpt'])->toBe(['nullable', 'string', 'max:500'])
            ->and(Textarea::make('excerpt')->rows(0)->getRows())->toBe(1);
    });

    it('refuses a list as the value of a hidden field but keeps text and numbers', function (mixed $value, bool $valid): void {
        $validator = validator(['type' => $value], Hidden::make('type')->getRules(null));

        expect($validator->passes())->toBe($valid);
    })->with([
        'text' => ['news', true],
        'number' => [5, true],
        'empty' => [null, true],
        'list' => [['x'], false],
        'forged key' => [['x' => 'y'], false],
    ]);

    it('hides a hidden field in the list and on the record page', function (): void {
        expect(Hidden::make('type')->isVisibleInList())->toBeFalse()
            ->and(Hidden::make('type')->isVisibleOnShow(new Article))->toBeFalse();
    });
});

describe('Number', function (): void {
    it('checks the number, integer and bounds', function (): void {
        expect(Number::make('price')->getRules(null)['price'])->toBe(['nullable', 'numeric'])
            ->and(Number::make('qty')->integer()->min(1)->max(10)->getRules(null)['qty'])->toBe(['nullable', 'integer', 'min:1', 'max:10']);
    });

    it('converts integers and keeps decimals as typed', function (): void {
        expect(Number::make('qty')->integer()->dehydrate('42'))->toBe(42)
            ->and(Number::make('price')->dehydrate('10.50'))->toBe('10.50')
            ->and(Number::make('price')->dehydrate(''))->toBeNull();
    });

    it('sets the arrow step', function (): void {
        expect(Number::make('a')->getStep())->toBe('any')
            ->and(Number::make('a')->integer()->getStep())->toBe('1')
            ->and(Number::make('a')->step(0.01)->getStep())->toBe('0.01');
    });
});

describe('Checkbox and Toggle', function (): void {
    it('saves an unchecked box as false', function (mixed $input, bool $expected): void {
        expect(Checkbox::make('a')->dehydrate($input))->toBe($expected)
            ->and(Toggle::make('a')->dehydrate($input))->toBe($expected);
    })->with([['1', true], ['0', false], [null, false], [true, true]]);

    it('shows an icon with a readable yes or no', function (): void {
        expect(displayed(Toggle::make('is_published')->display(new Article(['is_published' => true]))))->toContain('<svg')->toContain('Да')
            ->and(displayed(Toggle::make('is_published')->display(new Article(['is_published' => false]))))->toContain('Нет');
    });
});

describe('Select', function (): void {
    it('takes options from an array, an enum or a closure', function (): void {
        expect(Select::make('a')->options(['x' => 'Икс'])->getOptions())->toBe(['x' => 'Икс'])
            ->and(Select::make('a')->options(ArticleStatus::class)->getOptions())->toBe(['draft' => 'Черновик', 'review' => 'На проверке', 'published' => 'Опубликовано'])
            ->and(Select::make('a')->options(fn (): array => [1 => 'Один'])->getOptions())->toBe([1 => 'Один']);
    });

    it('allows only listed options', function (): void {
        $rules = Select::make('status')->options(['draft' => 'Черновик'])->getRules(null);
        $multiple = Select::make('tags')->options(['a' => 'А'])->multiple()->getRules(null);

        expect(validator(['status' => 'hacked'], $rules)->fails())->toBeTrue()
            ->and(validator(['status' => 'draft'], $rules)->passes())->toBeTrue()
            ->and(validator(['tags' => ['a', 'b']], $multiple)->fails())->toBeTrue()
            ->and(validator(['tags' => ['a']], $multiple)->passes())->toBeTrue()
            ->and(validator(['tags' => 'a'], $multiple)->fails())->toBeTrue();
    });

    it('turns enum values into strings for the form and labels for the list', function (): void {
        $record = new Article(['status' => ArticleStatus::Review, 'tags' => ['a', 'zzz']]);
        $status = Select::make('status')->options(ArticleStatus::class);
        $tags = Select::make('tags')->options(['a' => 'А'])->multiple();

        expect($status->hydrate($record))->toBe('review')
            ->and($status->display($record))->toBe('На проверке')
            ->and($tags->hydrate($record))->toBe(['a', 'zzz'])
            ->and($tags->display($record))->toBe('А, zzz')
            ->and($tags->display(new Article))->toBeNull()
            ->and($tags->hydrate(new Article))->toBe([]);
    });

    it('asks the options closure once', function (): void {
        $calls = 0;
        $field = Select::make('status')->options(function () use (&$calls): array {
            $calls++;

            return ['draft' => 'Черновик'];
        });

        $field->getRules(null);
        $field->display(new Article(['status' => 'draft']));
        $field->display(new Article(['status' => 'draft']));

        expect($calls)->toBe(1);
    });

    it('turns an enum default into its value', function (): void {
        expect(Select::make('status')->options(ArticleStatus::class)->default(ArticleStatus::Review)->formValue(null))->toBe('review')
            ->and(Select::make('tags')->multiple()->default(['a'])->formValue(null))->toBe(['a']);
    });

    it('drops empty values of a multiple select', function (): void {
        expect(Select::make('tags')->multiple()->dehydrate(['a', '', null, 'b']))->toBe(['a', 'b'])
            ->and(Select::make('tags')->multiple()->dehydrate(''))->toBe([]);
    });
});

describe('dates and times', function (): void {
    it('shows and edits a date', function (): void {
        $record = new Article(['published_on' => '2026-03-08']);

        expect(Date::make('published_on')->hydrate($record))->toBe('2026-03-08')
            ->and(Date::make('published_on')->display($record))->toBe('08.03.2026')
            ->and(Date::make('published_on')->display(new Article(['title' => 'не дата'])))->toBeNull()
            ->and(validator(['d' => '08.03.2026'], Date::make('d')->getRules(null))->fails())->toBeTrue();
    });

    it('shows and saves date and time in the admin time zone', function (): void {
        config(['app.timezone' => 'UTC', 'fastleo.timezone' => 'Europe/Moscow']);
        $record = new Article;
        $record->setRawAttributes(['starts_at' => '2026-03-08 09:30:00']);
        $field = DateTime::make('starts_at');

        expect($field->hydrate($record))->toBe('2026-03-08T12:30')
            ->and($field->display($record))->toBe('08.03.2026 12:30')
            ->and($field->dehydrate('2026-03-08T12:30'))->toBe('2026-03-08 09:30:00')
            ->and($field->dehydrate(''))->toBeNull();
    });

    it('falls back to the site time zone for an unknown admin time zone', function (): void {
        config(['app.timezone' => 'UTC', 'fastleo.timezone' => 'Mars/Olympus']);

        expect(DateTime::make('starts_at')->dehydrate('2026-03-08T12:30'))->toBe('2026-03-08 12:30:00');
    });

    it('turns a date default into the form format in the admin time zone', function (): void {
        config(['app.timezone' => 'UTC', 'fastleo.timezone' => 'Europe/Moscow']);
        Illuminate\Support\Facades\Date::setTestNow('2026-03-08 21:30:00');

        expect(Date::make('d')->default(fn (): CarbonInterface => now())->formValue(null))->toBe('2026-03-08')
            ->and(DateTime::make('d')->default(fn (): CarbonInterface => now())->formValue(null))->toBe('2026-03-09T00:30')
            ->and(Time::make('t')->default(now())->formValue(null))->toBe('00:30')
            ->and(Time::make('t')->default('09:00')->formValue(null))->toBe('09:00');
    });

    it('shows time without seconds', function (): void {
        $record = new Article(['opens_at' => '09:05:00']);

        expect(Time::make('opens_at')->hydrate($record))->toBe('09:05')
            ->and(Time::make('opens_at')->display($record))->toBe('09:05')
            ->and(Time::make('opens_at')->hydrate(new Article))->toBeNull();
    });
});

describe('Color', function (): void {
    it('shows a swatch only for a valid color', function (): void {
        expect(displayed(Color::make('color')->display(new Article(['color' => '#ff0000']))))->toContain('background-color: #ff0000')
            ->and(Color::make('color')->display(new Article(['color' => 'red;}body{display:none'])))->toBe('red;}body{display:none')
            ->and(Color::make('color')->getRules(null)['color'])->toBe(['nullable', 'hex_color']);
    });

    it('does not take a trailing line break for a color', function (): void {
        expect(displayed(Color::make('color')->display(new Article(['color' => "#fff\n"]))))->not->toContain('background-color');
    });
});
