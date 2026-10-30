<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Number;
use Fastleo\Support\ModelDefaults;
use Fastleo\Support\RecordWriter;
use Fastleo\Tests\Fixtures\Models\DefaultedThing;

beforeEach(function (): void {
    createDefaultedThingsTable();
    useFixtureResources('Defaults');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Включён ли переключатель с таким именем в HTML формы.
 */
function switchedOn(string $html, string $name): bool
{
    return preg_match('/id="field-'.$name.'"[^>]*\schecked\b/', $html) === 1;
}

it('takes the default of the field from the model and the column when the field has none', function (): void {
    $html = $this->get(route('fastleo.resources.create', 'defaulted'))->assertOk()->getContent();

    expect(switchedOn((string) $html, 'is_active'))->toBeTrue()
        ->and($html)->toContain('value="Без названия"')
        ->and($html)->toContain('value="0"')
        ->and($html)->toContain('value="basic"')
        ->and($html)->not->toContain('value="column"');
});

it('lets the default of the field win over the column', function (): void {
    $html = (string) $this->get(route('fastleo.resources.create', 'defaulted'))->getContent();

    expect(switchedOn($html, 'is_hidden'))->toBeTrue();
});

it('does not take a column expression for a value', function (): void {
    expect(ModelDefaults::for(Fastleo::resources()->find('defaulted')))
        ->toHaveKeys(['is_active', 'title', 'sort', 'kind'])
        ->not->toHaveKey('published_at')
        ->not->toHaveKey('note')
        ->not->toHaveKey('is_hidden');
});

it('saves what the admin entered, not the defaults', function (): void {
    $this->post(route('fastleo.resources.store', 'defaulted'), ['is_active' => '0', 'is_hidden' => '0', 'title' => 'Своё', 'sort' => '5', 'kind' => 'k'])
        ->assertRedirect();

    expect(DefaultedThing::query()->firstOrFail())->title->toBe('Своё')->is_active->toBeFalse();
});

it('fills a field the request did not send with its default() and keeps what was sent, even if empty', function (): void {
    $this->post(route('fastleo.resources.store', 'defaulted'), ['is_active' => '1', 'title' => 'Своё', 'sort' => '5', 'kind' => 'k'])
        ->assertRedirect();

    expect(DefaultedThing::query()->firstOrFail())->is_hidden->toBeTrue();

    $this->post(route('fastleo.resources.store', 'defaulted'), ['is_active' => '1', 'is_hidden' => '0', 'title' => 'Ещё', 'sort' => '5', 'kind' => 'k'])
        ->assertRedirect();

    expect(DefaultedThing::query()->where('title', 'Ещё')->firstOrFail())->is_hidden->toBeFalse();
});

it('lets a required field with a default() pass the check when the request did not send it', function (): void {
    $fields = [Number::make('sort')->integer()->required()->default(0)];
    $rules = RecordWriter::rules(Fastleo::resources()->find('defaulted'), null, $fields);

    expect(validator([], $rules)->fails())->toBeTrue()
        ->and(validator(RecordWriter::withDefaults($fields, []), $rules)->passes())->toBeTrue()
        ->and(RecordWriter::withDefaults($fields, ['sort' => '']))->toBe(['sort' => ''])
        ->and(RecordWriter::withDefaults([Number::make('other')->integer()], []))->toBe([]);
});

it('leaves a field empty when the column default does not fit the cast of the model, and the form still opens', function (): void {
    $this->get(route('fastleo.resources.create', 'defaulted'))->assertOk();

    expect(ModelDefaults::for(Fastleo::resources()->find('defaulted')))->not->toHaveKey('mood');
});

it('does not take a zero date of an old MySQL table as a value', function (): void {
    expect(ModelDefaults::for(Fastleo::resources()->find('defaulted')))->not->toHaveKey('legacy_at');
});
