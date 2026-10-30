<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('SavedOptions');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->article = Article::factory()->create(['type' => 'legacy', 'tags' => ['news', 'old'], 'layout' => 'wide']);
});

/**
 * Данные формы, как их отправляет браузер.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function savedOptionsData(array $overrides = []): array
{
    return ['title' => 'Статья', 'type' => 'legacy', 'tags' => ['news', 'old'], 'layout' => 'wide', ...$overrides];
}

describe('a saved value that is not among the options', function (): void {
    it('stays selected in the form of a list, a multiple list and radio buttons', function (): void {
        $this->article->update(['layout' => 'archive']);

        $this->get(route('fastleo.resources.edit', ['saved-options', $this->article->id]))
            ->assertOk()
            ->assertSee('<option value="legacy" selected>legacy</option>', false)
            ->assertSee('<option value="old" selected>old</option>', false)
            ->assertSee('value="archive"', false);
    });

    it('survives saving the record without changes', function (): void {
        $this->article->update(['layout' => 'archive']);

        $this->put(route('fastleo.resources.update', ['saved-options', $this->article->id]), savedOptionsData(['layout' => 'archive', 'title' => 'Новое']))
            ->assertSessionHasNoErrors();

        expect($this->article->fresh()->only(['title', 'type', 'tags', 'layout']))->toBe(['title' => 'Новое', 'type' => 'legacy', 'tags' => ['news', 'old'], 'layout' => 'archive']);
    });

    it('is not offered for a new record and is not a loophole for other values', function (): void {
        $this->post(route('fastleo.resources.store', 'saved-options'), savedOptionsData())->assertSessionHasErrors(['type', 'tags.1']);
        $this->put(route('fastleo.resources.update', ['saved-options', $this->article->id]), savedOptionsData(['type' => 'hacked']))->assertSessionHasErrors('type');
        $this->put(route('fastleo.resources.update', ['saved-options', $this->article->id]), savedOptionsData(['tags' => ['news', 'hacked']]))->assertSessionHasErrors('tags.1');
        $this->put(route('fastleo.resources.update', ['saved-options', $this->article->id]), savedOptionsData(['layout' => 'hacked']))->assertSessionHasErrors('layout');

        expect($this->article->fresh()->type)->toBe('legacy');
    });

    it('can be replaced by a listed option, after which the old value is gone', function (): void {
        $this->put(route('fastleo.resources.update', ['saved-options', $this->article->id]), savedOptionsData(['type' => 'news']))->assertSessionHasNoErrors();
        $this->put(route('fastleo.resources.update', ['saved-options', $this->article->id]), savedOptionsData(['type' => 'legacy']))->assertSessionHasErrors('type');
    });
});
