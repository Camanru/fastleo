<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('MultipleSelect');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('shows every option of a multiple list as a chip and checks the saved ones', function (): void {
    $article = Article::factory()->create(['type' => 'news', 'tags' => ['guide'], 'keywords' => ['hit']]);

    $html = (string) $this->get(route('fastleo.resources.edit', ['multiple-select', $article->id]))->assertOk()->getContent();

    expect($html)->toMatch('/<legend[^>]*>\s*Метки\s*<span class="text-danger"/')
        ->and($html)->toMatch('/name="tags\[\]"\s+value="news"\s+class="sr-only"\s*>/')
        ->and($html)->toMatch('/name="tags\[\]"\s+value="guide"\s+class="sr-only"\s+checked/')
        ->and($html)->toContain('<input type="hidden" name="tags" value="">')
        ->and($html)->toContain('<template data-chip>')
        ->and($html)->not->toMatch('/name="tags\[\]"[^>]*required/')
        ->and($html)->not->toContain('<select id="field-tags"');
});

it('shows a read-only multiple list without names so that nothing is sent', function (): void {
    $article = Article::factory()->create(['tags' => ['news'], 'keywords' => ['hit']]);

    $html = (string) $this->get(route('fastleo.resources.edit', ['multiple-select', $article->id]))->assertOk()->getContent();

    expect($html)->toMatch('/value="hit"\s+class="sr-only"\s+checked\s+disabled/')
        ->and($html)->not->toContain('name="keywords');
});

it('requires at least one chip of a required multiple list on the server', function (): void {
    $this->post(route('fastleo.resources.store', 'multiple-select'), ['title' => 'Статья', 'type' => 'news', 'tags' => ''])
        ->assertSessionHasErrors('tags');

    expect(Article::query()->count())->toBe(0);
});

it('saves the checked chips of the options for the chosen dependency', function (): void {
    $this->post(route('fastleo.resources.store', 'multiple-select'), ['title' => 'Акция', 'type' => 'promo', 'tags' => ['sale']])
        ->assertSessionHasNoErrors();

    expect(Article::query()->sole()->tags)->toBe(['sale']);
});

it('offers the chips of the chosen dependency when the form comes back with an error', function (): void {
    $html = (string) $this->from(route('fastleo.resources.create', 'multiple-select'))
        ->followingRedirects()
        ->post(route('fastleo.resources.store', 'multiple-select'), ['title' => '', 'type' => 'promo', 'tags' => ['sale']])
        ->getContent();

    expect($html)->toMatch('/name="tags\[\]"\s+value="sale"\s+class="sr-only"\s+checked/')
        ->and($html)->not->toContain('value="guide"');
});

it('keeps the id of the field on the chip list when there are no options yet', function (): void {
    $html = (string) $this->get(route('fastleo.resources.create', 'multiple-select'))->assertOk()->getContent();

    expect($html)->toMatch('/<div\s+id="field-tags"\s+class="flex flex-wrap gap-2" data-chips>\s*<\/div>/');
});
