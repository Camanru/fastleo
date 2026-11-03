<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('opens a record in the trash in the window with the eye when the window is on', function (): void {
    config(['fastleo.record_view' => 'modal']);
    $article = Article::factory()->create();
    $article->delete();

    $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))
        ->assertOk()
        ->assertSee('data-record-view="'.route('fastleo.resources.show', ['articles', $article->id, 'frame' => 1]).'"', false);
});
