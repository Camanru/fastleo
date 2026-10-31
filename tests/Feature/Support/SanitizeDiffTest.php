<?php

declare(strict_types=1);

use Fastleo\Support\Html\SanitizeDiff;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config(['fastleo.locales' => ['ru', 'en']]);
    Schema::create('doctor_rich_notes', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->longText('body')->nullable();
        $table->longText('summary')->nullable();
    });
    useFixtureResources('SanitizeDiff');
});

/**
 * Строка отчёта по разделу и полю или null, если поле в отчёт не попало.
 *
 * @return array<string, mixed>|null
 */
function sanitizeDiffRow(string $resource, string $field): ?array
{
    return collect(new SanitizeDiff()->run())->first(fn (array $row): bool => $row['resource'] === $resource && $row['field'] === $field);
}

it('compares only RichText fields: a plain text field with markup is left alone', function (): void {
    DB::table('doctor_rich_notes')->insert(['title' => '<div class="x">заголовок</div>', 'body' => '<div class="x">текст</div>']);

    expect(sanitizeDiffRow('mixed-notes', 'title'))->toBeNull()
        ->and(sanitizeDiffRow('mixed-notes', 'body'))->toMatchArray(['checked' => 1, 'changed' => 1]);
});

it('checks every language of a translatable field and shows the language of the changed text', function (): void {
    DB::table('doctor_rich_notes')->insert([
        ['summary' => json_encode(['ru' => '<p>Чисто</p>', 'en' => '<div class="x">Dirty</div>'], JSON_UNESCAPED_UNICODE)],
        ['summary' => json_encode(['ru' => '<p>Чисто</p>', 'en' => '<p>Clean</p>'], JSON_UNESCAPED_UNICODE)],
    ]);

    $row = sanitizeDiffRow('mixed-notes', 'summary');

    expect($row)->toMatchArray(['checked' => 2, 'changed' => 1])
        ->and($row['records'])->toHaveCount(1)
        ->and($row['records'][0])->toMatchArray(['key' => '1', 'locale' => 'en'])
        ->and($row['records'][0]['before'])->toBe('<div class="x">Dirty</div>');
});

it('skips empty and non-text languages and records that hold no text at all', function (): void {
    DB::table('doctor_rich_notes')->insert([
        ['summary' => json_encode(['ru' => '', 'en' => '   '])],
        ['summary' => json_encode(['ru' => null, 'en' => 5])],
        ['summary' => json_encode(['ru' => '<p>Чисто</p>', 'en' => ''])],
    ]);

    expect(sanitizeDiffRow('mixed-notes', 'summary'))->toBeNull();

    DB::table('doctor_rich_notes')->insert(['summary' => json_encode(['ru' => '<div class="x">грязно</div>', 'en' => ''])]);

    expect(sanitizeDiffRow('mixed-notes', 'summary'))->toMatchArray(['checked' => 2, 'changed' => 1]);
});

it('treats a translatable value that is not a JSON object as one text', function (): void {
    DB::table('doctor_rich_notes')->insert(['summary' => '<div class="x">просто текст</div>']);

    expect(sanitizeDiffRow('mixed-notes', 'summary'))->toMatchArray(['checked' => 1, 'changed' => 1]);
});

it('skips read-only sections and sections that fail to describe themselves', function (): void {
    DB::table('doctor_rich_notes')->insert(['body' => '<div class="x">текст</div>']);

    $resources = collect(new SanitizeDiff()->run())->pluck('resource')->unique()->all();

    expect($resources)->toBe(['mixed-notes']);
});

it('skips a section whose table cannot be read', function (): void {
    useFixtureResources('SanitizeDiffGhost');

    expect(new SanitizeDiff()->run())->toBe([]);
});
