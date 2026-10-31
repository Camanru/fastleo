<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Select;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('Badges');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Классы цвета метки, как их ставит компонент `<x-fastleo::badge>`.
 */
function badgeClasses(string $tone): string
{
    return ['success' => 'bg-success-soft text-success', 'warning' => 'bg-warning-soft text-warning', 'danger' => 'bg-danger-soft text-danger', 'info' => 'bg-info-soft text-info', 'neutral' => 'bg-sunken text-ink-2'][$tone];
}

describe('list and view', function (): void {
    it('colors the status of an enum by its tone() method', function (): void {
        Article::factory()->create(['status' => 'published', 'type' => '', 'tags' => []]);

        $this->get(route('fastleo.resources.index', 'badges'))
            ->assertOk()
            ->assertSee(badgeClasses('success'), false)
            ->assertSee('Опубликовано');
    });

    it('colors a list value by the map and leaves the rest neutral', function (): void {
        Article::factory()->create(['status' => 'draft', 'type' => 'promo', 'tags' => []]);
        Article::factory()->create(['status' => 'draft', 'type' => 'news', 'tags' => []]);

        $this->get(route('fastleo.resources.index', 'badges'))
            ->assertOk()
            ->assertSee(badgeClasses('info'), false)
            ->assertSee('Акция')
            ->assertSee('Новость');
    });

    it('draws one badge per selected value of a multiple list', function (): void {
        $article = Article::factory()->create(['status' => 'draft', 'tags' => ['news', 'promo']]);

        $this->get(route('fastleo.resources.show', ['badges', $article->id]))
            ->assertOk()
            ->assertSeeInOrder(['bg-success-soft text-success', 'Новости', 'bg-danger-soft text-danger', 'Акции']);
    });

    it('shows a dash for an empty value instead of an empty badge', function (): void {
        $article = Article::factory()->create(['status' => 'draft', 'type' => '', 'tags' => []]);

        $html = $this->get(route('fastleo.resources.show', ['badges', $article->id]))->assertOk()->getContent();

        // Единственная метка на странице — статус; пустые вид и метки дают прочерк, а не пустую метку.
        expect(substr_count((string) $html, 'rounded-badge'))->toBe(1);
    });

    it('lets the own formatUsing() of the field win over the badge', function (): void {
        Article::factory()->create(['status' => 'draft', 'layout' => 'wide', 'tags' => []]);

        $this->get(route('fastleo.resources.index', 'badges'))
            ->assertOk()
            ->assertSee('свой wide')
            ->assertDontSee('Широкий');
    });

    it('escapes the label of a badge', function (): void {
        Article::factory()->create(['status' => 'draft', 'type' => 'x', 'tags' => []]);
        Article::query()->update(['type' => '<script>1</script>']);

        $this->get(route('fastleo.resources.index', 'badges'))
            ->assertOk()
            ->assertDontSee('<script>1</script>', false)
            ->assertSee('&lt;script&gt;1&lt;/script&gt;', false);
    });
});

describe('description', function (): void {
    it('rejects an unknown tone', function (): void {
        Select::make('type')->options(['a' => 'A'])->badges(['a' => 'purple']);
    })->throws(InvalidArgumentException::class, 'purple');

    it('keeps the form, the export value and the import untouched', function (): void {
        $field = Select::make('type', 'Вид')->options(['news' => 'Новость'])->badges(['news' => 'info']);
        $article = new Article(['type' => 'news']);

        expect($field->hasBadges())->toBeTrue()
            ->and($field->exportValue($article))->toBe('Новость')
            ->and($field->importValue('Новость'))->toBe('news')
            ->and($field->display($article))->toBe('Новость');
    });

    it('does not draw badges for a field without badges()', function (): void {
        expect(Select::make('type')->options(['a' => 'A'])->hasBadges())->toBeFalse();
    });
});
