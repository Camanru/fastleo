<?php

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Fastleo\ArticleResource;
use Workbench\App\Fastleo\FeedbackResource;
use Workbench\App\Models\Article;
use Workbench\App\Models\Feedback;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('marks a record read when it is opened, quietly and without touching the record', function (): void {
    $feedback = Feedback::factory()->create(['updated_at' => now()->subDay()]);
    $updatedAt = $feedback->fresh()?->updated_at?->toDateTimeString();

    expect(FeedbackResource::badge())->toBe(1);

    $this->get(route('fastleo.resources.show', ['feedback', $feedback->id]))
        ->assertOk()
        ->assertSee(route('fastleo.resources.action', ['feedback', 'mark-unread']), false)
        ->assertDontSee(route('fastleo.resources.action', ['feedback', 'mark-read']), false);

    $fresh = $feedback->fresh();

    expect($fresh?->read_at)->not->toBeNull()
        ->and($fresh?->updated_at?->toDateTimeString())->toBe($updatedAt)
        ->and(Activity::query()->count())->toBe(0)
        ->and(FeedbackResource::badge())->toBeNull();
});

it('keeps the first read time when a record is opened again', function (): void {
    $feedback = Feedback::factory()->create(['read_at' => now()->subWeek()]);

    $this->get(route('fastleo.resources.show', ['feedback', $feedback->id]))->assertOk();

    expect($feedback->fresh()?->read_at?->toDateString())->toBe(now()->subWeek()->toDateString());
});

it('highlights only unread rows of the list', function (): void {
    $unread = Feedback::factory()->create();
    Feedback::factory()->create(['read_at' => now()]);
    $html = (string) $this->get(route('fastleo.resources.index', 'feedback'))->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $rows = [...$document->querySelectorAll('tr[data-record], li[data-record]')];

    // Одна непросмотренная запись: метка у её строки таблицы и у карточки на телефоне; лишних атрибутов в тегах нет.
    expect(array_map(fn (Element $row): string => $row->getAttribute('data-record').':'.($row->hasAttribute('data-unread') ? 'unread' : 'read'), $rows))
        ->toEqualCanonicalizing([$unread->id.':unread', $unread->id.':unread', ($unread->id + 1).':read', ($unread->id + 1).':read'])
        ->and(array_filter($rows, fn (Element $row): bool => array_any($row->getAttributeNames(), fn (string $name): bool => str_contains($name, '<'))))->toBe([]);
});

it('does not highlight records selected without the read column', function (): void {
    $resource = resolve(FeedbackResource::class);
    Feedback::factory()->create();

    expect($resource->isUnread(Feedback::query()->select('id', 'name')->firstOrFail()))->toBeFalse()
        ->and($resource->isUnread(Feedback::query()->firstOrFail()))->toBeTrue();
});

it('counts the rows of the list in the badge when the section groups its query', function (): void {
    $resource = new class extends Resource
    {
        protected static string $model = Feedback::class;

        protected static ?string $readColumn = 'read_at';

        public function fields(): array
        {
            return [];
        }

        public function query(Builder $query): Builder
        {
            return $query->select('name')->groupBy('name');
        }
    };
    Feedback::factory()->count(3)->create(['name' => 'Анна']);
    Feedback::factory()->create(['name' => 'Борис']);

    expect($resource::badge())->toBe(2);
});

it('marks records the section sees past a global scope of the model, trashed ones included', function (): void {
    $resource = new class extends Resource
    {
        protected static string $model = Feedback::class;

        protected static ?string $readColumn = 'read_at';

        public function fields(): array
        {
            return [];
        }

        public function query(Builder $query): Builder
        {
            return $query->withoutGlobalScope('hidden');
        }
    };
    $feedback = Feedback::factory()->create();
    Feedback::addGlobalScope('hidden', fn (Builder $query): Builder => $query->whereKeyNot($feedback->id));

    try {
        expect($resource::badge())->toBe(1)
            ->and($resource->markRead([$feedback->id]))->toBe(1)
            ->and($resource::badge())->toBeNull();
    } finally {
        Feedback::clearBootedModels();
    }
});

it('leaves the record page after marking it unread, or it would be read again at once', function (string $page): void {
    $feedback = Feedback::factory()->create(['read_at' => now()]);
    $from = route('fastleo.resources.'.$page, ['feedback', $feedback->id]);

    $this->from($from)->post(route('fastleo.resources.action', ['feedback', 'mark-unread']), ['ids' => [(string) $feedback->id]])
        ->assertRedirect(route('fastleo.resources.index', 'feedback'))
        ->assertSessionHas('fastleo.success', 'Отмечено: 1.');

    $parent = route('fastleo.resources.show', ['articles', 1]);
    $this->from($from.'?return='.urlencode($parent))->post(route('fastleo.resources.action', ['feedback', 'mark-unread']), ['ids' => [(string) $feedback->id]])
        ->assertRedirect($parent);

    expect($feedback->fresh()?->read_at)->toBeNull();
})->with(['show', 'edit']);

it('stays in the list after marking records unread there', function (): void {
    $feedback = Feedback::factory()->create(['read_at' => now()]);
    $list = route('fastleo.resources.index', ['feedback', 'page' => 2]);

    $this->from($list)->post(route('fastleo.resources.action', ['feedback', 'mark-unread']), ['ids' => [(string) $feedback->id]])
        ->assertRedirect($list);
});

it('marks selected records read and unread by actions, even in a read-only section', function (): void {
    $records = Feedback::factory()->count(2)->create();
    $ids = $records->map(fn (Feedback $feedback): string => (string) $feedback->id)->all();

    $this->post(route('fastleo.resources.action', ['feedback', 'mark-read']), ['ids' => $ids])
        ->assertRedirect()
        ->assertSessionHas('fastleo.success', 'Отмечено: 2.');

    expect(Feedback::query()->whereNull('read_at')->count())->toBe(0);

    $this->post(route('fastleo.resources.action', ['feedback', 'mark-unread']), ['ids' => [$ids[0]]])
        ->assertSessionHas('fastleo.success', 'Отмечено: 1.');

    expect(FeedbackResource::badge())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

it('does nothing for a section without the read column', function (): void {
    $resource = resolve(ArticleResource::class);
    $article = Article::factory()->create();

    expect(ArticleResource::readColumn())->toBeNull()
        ->and($resource->isUnread($article))->toBeFalse()
        ->and($resource->markRead([$article->id]))->toBe(0)
        ->and(array_keys($resource->listActions()))->not->toContain('mark-read', 'mark-unread');
});

it('describes the read column in inspect', function (): void {
    $this->artisan('fastleo:inspect', ['resource' => 'feedback', '--json' => true])
        ->expectsOutputToContain('"read_column": "read_at"')
        ->assertSuccessful();
});
