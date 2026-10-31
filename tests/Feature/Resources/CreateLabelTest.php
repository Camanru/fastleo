<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Workbench\App\Models\Article;

beforeEach(fn () => $this->actingAs(admin(), Fastleo::GUARD));

it('names the create button of the list after the section, also when the list is empty', function (): void {
    useFixtureResources('CreateLabel');

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('Добавить заявку')->assertDontSee('Создать');

    Article::factory()->create();

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('Добавить заявку')->assertDontSee('Создать');
});

it('says "Создать" unless the section has its own label', function (): void {
    useFixtureResources('Related');

    $this->get(route('fastleo.resources.index', 'articles'))->assertOk()->assertSee('Создать')->assertDontSee('Добавить заявку');
});
