<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Fastleo\Tests\Fixtures\Poll\ArticleResource;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Category;

beforeEach(function (): void {
    useFixtureResources('Poll');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Раздел рубрик с заданным периодом автообновления (своё свойство `$poll`: общее с другими разделами портило бы их).
 */
function pollingResource(?int $poll): Resource
{
    $class = new class extends Resource
    {
        protected static string $model = Category::class;

        protected static ?int $poll = null;

        public static function setPoll(?int $poll): void
        {
            self::$poll = $poll;
        }

        public function fields(): array
        {
            return [Text::make('title')];
        }
    };
    $class::setPoll($poll);

    return $class;
}

describe('the period', function (): void {
    it('is off by default, rounded up to 5 seconds and limited to an hour', function (): void {
        expect(pollingResource(null)::poll())->toBeNull()
            ->and(pollingResource(1)::poll())->toBe(5)
            ->and(pollingResource(10)::poll())->toBe(10)
            ->and(pollingResource(90000)::poll())->toBe(3600)
            ->and(ArticleResource::poll())->toBe(5);
    });

    it('is reported by fastleo:inspect', function (): void {
        Artisan::call('fastleo:inspect poll-articles --json');

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['poll'])->toBe(5);
    });
});

describe('the pages', function (): void {
    it('refreshes the list region of a section with $poll', function (): void {
        $this->get(route('fastleo.resources.index', 'poll-articles'))
            ->assertOk()
            ->assertSee('data-list-region', false)
            ->assertSee('fastleoPoll({ interval: 5 })', false);
    });

    it('leaves the region without the component when the section does not poll', function (): void {
        $this->get(route('fastleo.resources.index', 'categories'))
            ->assertOk()
            ->assertSee('data-list-region', false)
            ->assertDontSee('fastleoPoll', false);
    });

    it('hands the period of the child section to a related table', function (): void {
        $category = Category::factory()->create();

        $this->get(route('fastleo.resources.show', ['categories', $category->id]))
            ->assertOk()
            ->assertSee('poll: 5', false);
    });
});
