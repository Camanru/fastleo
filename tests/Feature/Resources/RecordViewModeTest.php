<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\RecordViewMode\ArticleResource;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('RecordViewMode');
    $this->actingAs(admin(), Fastleo::GUARD);
    $this->article = Article::factory()->create(['title' => 'Приточная']);
});

afterEach(function (): void {
    ArticleResource::$recordView = null;
    ArticleResource::$readOnlyFields = false;
    ArticleResource::$canUpdate = true;
});

describe('own mode of a section', function (): void {
    it('shows the record in a window while the site has no record page at all', function (): void {
        config(['fastleo.record_view' => false]);
        ArticleResource::$recordView = 'modal';
        $window = route('fastleo.resources.show', ['articles', $this->article->id, 'frame' => 1]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('data-record-view="'.$window.'"', false);
        $this->get($window)->assertOk()->assertSee('Приточная')->assertDontSee('fastleo-menu', false);
        expect(ArticleResource::recordViewMode())->toBe('modal')
            ->and(new ArticleResource()->viewModalUrl(new ArticleResource()->viewUrl($this->article)))->toBe($window);
    });

    it('has no record page for the section while the site has one for the others', function (): void {
        config(['fastleo.record_view' => 'modal']);
        ArticleResource::$recordView = false;

        $this->get(route('fastleo.resources.show', ['articles', $this->article->id]))->assertNotFound();
        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertDontSee('data-record-view', false)
            ->assertSee('href="'.route('fastleo.resources.edit', ['articles', $this->article->id]).'"', false);
        expect(ArticleResource::hasRecordView())->toBeFalse()
            ->and(new ArticleResource()->viewModalUrl(new ArticleResource()->viewUrl($this->article)))->toBeNull();
    });

    it('opens as a page and ignores the window mark', function (): void {
        config(['fastleo.record_view' => 'modal']);
        ArticleResource::$recordView = 'page';

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee(route('fastleo.resources.show', ['articles', $this->article->id]), false)->assertDontSee('data-record-view', false);
        $this->get(route('fastleo.resources.show', ['articles', $this->article->id, 'frame' => 1]))->assertOk()->assertSee('Приточная')->assertSee('fastleo-menu', false);
        expect(ArticleResource::recordViewInModal())->toBeFalse();
    });

    it('follows the config while the section says nothing, and reads a wrong value of the config as a page', function (): void {
        config(['fastleo.record_view' => 'page']);
        expect(ArticleResource::recordViewMode())->toBe('page')->and(ArticleResource::recordViewSetting())->toBeNull();

        config(['fastleo.record_view' => false]);
        expect(ArticleResource::recordViewMode())->toBeFalse();

        config(['fastleo.record_view' => 'modall']);
        expect(ArticleResource::recordViewMode())->toBe('page');
    });

    it('sends the administrator who cannot change the record to its page, and marks the address of a notification', function (): void {
        config(['fastleo.record_view' => false]);
        ArticleResource::$recordView = 'page';
        ArticleResource::$canUpdate = false;
        $resource = new ArticleResource();

        expect($resource->openUrl($this->article))->toBe(route('fastleo.resources.show', ['articles', $this->article->id]))
            ->and($resource->recordAddress($this->article, false))->toBe(route('fastleo.resources.show', ['articles', $this->article->id], false))
            ->and(Fastleo::recordUrl($this->article, false))->toBe(route('fastleo.resources.show', ['articles', $this->article->id], false));
    });
});

describe('form without a field to change', function (): void {
    it('has the save button while there is a field that the administrator may change', function (): void {
        $this->get(route('fastleo.resources.edit', ['articles', $this->article->id]))->assertOk()->assertSee('data-save', false);
        $this->get(route('fastleo.resources.create', 'articles'))->assertOk()->assertSee('data-save', false);
    });

    it('has no save buttons when every field is read-only', function (): void {
        ArticleResource::$readOnlyFields = true;

        $this->get(route('fastleo.resources.edit', ['articles', $this->article->id]))
            ->assertOk()
            ->assertSee('Приточная')
            ->assertDontSee('data-save', false)
            ->assertDontSee('Сохранить и выйти');
    });
});
