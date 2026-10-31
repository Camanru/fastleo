<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Policies\NoUpdateArticlePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    useFixtureResources('QuickToggle');
    $this->me = admin(['name' => 'Анна']);
    $this->actingAs($this->me, Fastleo::GUARD);
});

/**
 * Адрес быстрого переключателя поля записи раздела.
 */
function toggleUrl(string $slug, Article $article, string $field): string
{
    return route('fastleo.resources.toggle', [$slug, $article->id, $field]);
}

describe('list', function (): void {
    it('shows the icon as a button that switches the value', function (): void {
        $article = Article::factory()->create(['is_published' => false, 'is_featured' => false]);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('action="'.toggleUrl('articles', $article, 'is_published').'"', false)
            ->assertSee('name="value" value="1"', false)
            ->assertDontSee(toggleUrl('articles', $article, 'is_featured'), false);
    });

    it('offers the opposite value for a published record', function (): void {
        Article::factory()->create(['is_published' => true]);

        $this->get(route('fastleo.resources.index', 'articles'))->assertSee('name="value" value="0"', false);
    });

    it('leaves a plain icon without the update right', function (): void {
        $article = Article::factory()->create();
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertDontSee(toggleUrl('articles', $article, 'is_published'), false);
    });

    it('leaves a plain icon for a read-only or hidden field', function (): void {
        $article = Article::factory()->create();

        $this->get(route('fastleo.resources.index', 'frozen-articles'))
            ->assertOk()
            ->assertDontSee(toggleUrl('frozen-articles', $article, 'is_published'), false)
            ->assertDontSee(toggleUrl('frozen-articles', $article, 'is_featured'), false);
    });

    it('leaves a plain icon in the trash', function (): void {
        $article = Article::factory()->create();
        $article->delete();

        $this->get(route('fastleo.resources.index', ['resource' => 'articles', 'trashed' => 1]))
            ->assertOk()
            ->assertDontSee(toggleUrl('articles', $article, 'is_published'), false);
    });
});

describe('switching', function (): void {
    it('writes the value through Eloquent, answers JSON and leaves a trace in the activity log', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        Activity::query()->delete();

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])
            ->assertOk()
            ->assertExactJson(['value' => true]);

        expect($article->refresh()->is_published)->toBeTrue();

        $entry = Activity::query()->sole();
        expect($entry->event)->toBe('updated')
            ->and($entry->user_name)->toBe('Анна')
            ->and(array_keys($entry->changes))->toBe(['is_published'])
            ->and($entry->changes['is_published']['new'])->toBeTrue();

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 0])->assertOk()->assertExactJson(['value' => false]);
        expect($article->refresh()->is_published)->toBeFalse();
    });

    it('returns to the page with a message without JSON', function (): void {
        $article = Article::factory()->create(['is_published' => false]);

        $this->from(route('fastleo.resources.index', 'articles'))
            ->post(toggleUrl('articles', $article, 'is_published'), ['value' => '1'])
            ->assertRedirect(route('fastleo.resources.index', 'articles'))
            ->assertSessionHas('fastleo.success');

        expect($article->refresh()->is_published)->toBeTrue();
    });

    it('does not touch other columns of the record', function (): void {
        $article = Article::factory()->create(['title' => 'Прежний', 'is_published' => false, 'is_featured' => true]);

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])->assertOk();

        expect($article->refresh()->title)->toBe('Прежний')->and($article->is_featured)->toBeTrue();
    });

    it('asks for an explicit value and refuses a wrong one', function (mixed $data): void {
        $article = Article::factory()->create(['is_published' => true]);

        $this->postJson(toggleUrl('articles', $article, 'is_published'), $data)->assertUnprocessable();

        expect($article->refresh()->is_published)->toBeTrue();
    })->with([
        'no value' => [[]],
        'not a boolean' => [['value' => 'maybe']],
        'array' => [['value' => [1]]],
    ]);
});

describe('a refusal while saving', function (): void {
    it('explains why the database refused the value and keeps the record', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        Article::updating(function (): never {
            throw new QueryException('sqlite', 'update articles', [], new PDOException('FOREIGN KEY constraint failed'));
        });

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Не удалось сохранить или удалить: запись связана с другими записями или указана несуществующая связанная запись.']);

        expect($article->refresh()->is_published)->toBeFalse();
    });

    it('says so when an observer of the site cancels the switch', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        Article::updating(fn (): bool => false);

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Сайт отменил сохранение (обработчик модели вернул false). Изменения не записаны.']);

        expect($article->refresh()->is_published)->toBeFalse();
    });
});

describe('refusals', function (): void {
    it('refuses without the update right and leaves the value', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])->assertForbidden();

        expect($article->refresh()->is_published)->toBeFalse();
    });

    it('refuses a field without quickToggle() and a field that is not a checkbox', function (string $field): void {
        $article = Article::factory()->create(['is_featured' => false, 'title' => 'Прежний']);

        $this->postJson(toggleUrl('articles', $article, $field), ['value' => 1])->assertNotFound();

        expect($article->refresh()->is_featured)->toBeFalse()->and($article->title)->toBe('Прежний');
    })->with(['plain toggle' => 'is_featured', 'text' => 'title', 'column outside the form' => 'views', 'missing' => 'nothing']);

    it('refuses a read-only and a hidden field even with quickToggle()', function (string $field): void {
        $article = Article::factory()->create(['is_published' => false, 'is_featured' => false]);

        $this->postJson(toggleUrl('frozen-articles', $article, $field), ['value' => 1])->assertForbidden();

        expect($article->refresh()->is_published)->toBeFalse()->and($article->is_featured)->toBeFalse();
    })->with(['read-only' => 'is_published', 'hidden' => 'is_featured']);

    it('refuses a record in the trash, a missing record and a missing section', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        $article->delete();

        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])->assertNotFound();
        $this->postJson(route('fastleo.resources.toggle', ['articles', 999999, 'is_published']), ['value' => 1])->assertNotFound();
        $this->postJson(route('fastleo.resources.toggle', ['nothing', 1, 'is_published']), ['value' => 1])->assertNotFound();
    });

    it('does not switch by GET and needs a session and a csrf token', function (): void {
        $article = Article::factory()->create(['is_published' => false]);

        $this->get(toggleUrl('articles', $article, 'is_published'))->assertStatus(405);

        $this->app['env'] = 'production';
        $this->post(toggleUrl('articles', $article, 'is_published'), ['value' => 1])->assertStatus(419);

        $this->app['env'] = 'testing';
        $this->app['auth']->guard(Fastleo::GUARD)->logout();
        $this->flushSession();
        $this->postJson(toggleUrl('articles', $article, 'is_published'), ['value' => 1])->assertUnauthorized();

        expect($article->refresh()->is_published)->toBeFalse();
    });
});

describe('field conditions', function (): void {
    it('shows the button only while visibleWhen() is met and refuses the write otherwise', function (): void {
        $off = Article::factory()->create(['is_featured' => false, 'is_published' => false]);
        $on = Article::factory()->create(['is_featured' => true, 'is_published' => false]);

        $this->get(route('fastleo.resources.index', 'conditional-articles'))
            ->assertOk()
            ->assertDontSee(toggleUrl('conditional-articles', $off, 'is_published'), false)
            ->assertSee(toggleUrl('conditional-articles', $on, 'is_published'), false);

        $this->postJson(toggleUrl('conditional-articles', $off, 'is_published'), ['value' => 0])->assertForbidden();
        expect($off->refresh()->is_published)->toBeFalse();
    });

    it('applies the rules of the field when the condition is met', function (): void {
        $article = Article::factory()->create(['is_featured' => true, 'is_published' => true]);

        $this->postJson(toggleUrl('conditional-articles', $article, 'is_published'), ['value' => 1])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Публиковать нельзя.']);

        $this->postJson(toggleUrl('conditional-articles', $article, 'is_published'), ['value' => 0])->assertOk();
        expect($article->refresh()->is_published)->toBeFalse();
    });

    it('does not offer a button for a field that is only in the list', function (): void {
        $article = Article::factory()->create(['is_published' => false]);

        $this->get(route('fastleo.resources.index', 'listonly-articles'))
            ->assertOk()
            ->assertDontSee(toggleUrl('listonly-articles', $article, 'is_published'), false);
        $this->postJson(toggleUrl('listonly-articles', $article, 'is_published'), ['value' => 1])->assertForbidden();
    });
});

describe('SetToggle action', function (): void {
    it('skips records whose field the form would not write', function (): void {
        $article = Article::factory()->create(['is_published' => false, 'is_featured' => false]);

        $this->from(route('fastleo.resources.index', 'frozen-articles'))
            ->post(route('fastleo.resources.action', ['frozen-articles', 'set-is-published-on']), ['ids' => [$article->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 0. Пропущено, поле недоступно для изменения: 1.');
        $this->post(route('fastleo.resources.action', ['frozen-articles', 'set-is-featured-on']), ['ids' => [$article->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 0. Пропущено, поле недоступно для изменения: 1.');

        expect($article->refresh()->is_published)->toBeFalse()->and($article->is_featured)->toBeFalse();
    });

    it('applies the rules of the field and skips the records they reject', function (): void {
        $draft = Article::factory()->create(['is_featured' => true, 'is_published' => false]);
        $published = Article::factory()->create(['is_featured' => true, 'is_published' => true]);

        $this->post(route('fastleo.resources.action', ['conditional-articles', 'set-is-published-on']), ['ids' => [$draft->id, $published->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 0. Пропущено, значение не прошло проверку поля: 1 (Публиковать нельзя.)');
        $this->post(route('fastleo.resources.action', ['conditional-articles', 'set-is-published-off']), ['ids' => [$published->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 1.');

        expect($draft->refresh()->is_published)->toBeFalse()->and($published->refresh()->is_published)->toBeFalse();
    });

    it('does not count a record whose saving an observer cancelled', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        Article::updating(fn (): bool => false);

        $this->post(route('fastleo.resources.action', ['articles', 'set-is-published-on']), ['ids' => [$article->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 0.');

        expect($article->refresh()->is_published)->toBeFalse();
    });

    it('shows both buttons with their labels', function (): void {
        Article::factory()->create();

        $this->get(route('fastleo.resources.index', 'articles'))
            ->assertOk()
            ->assertSee('Опубликовать')
            ->assertSee('Снять с публикации');
        expect(array_keys(Fastleo::resources()->find('articles')?->listActions() ?? []))->toContain('set-is-published-on', 'set-is-published-off');
    });

    it('shows on the page of one record only the button that changes the value', function (): void {
        $shown = Article::factory()->create(['is_published' => true]);
        $hidden = Article::factory()->create(['is_published' => false]);

        $this->get(route('fastleo.resources.edit', ['articles', $shown->id]))
            ->assertOk()
            ->assertSee('Снять с публикации')
            ->assertDontSee('Опубликовать');
        $this->get(route('fastleo.resources.show', ['articles', $hidden->id]))
            ->assertOk()
            ->assertSee('Опубликовать')
            ->assertDontSee('Снять с публикации');
    });

    it('sets the value to the selected records, counts only the changed ones and skips the rest', function (): void {
        [$hidden, $shown, $other] = [
            Article::factory()->create(['is_published' => false]),
            Article::factory()->create(['is_published' => true]),
            Article::factory()->create(['is_published' => false]),
        ];
        Activity::query()->delete();

        $this->from(route('fastleo.resources.index', 'articles'))
            ->post(route('fastleo.resources.action', ['articles', 'set-is-published-on']), ['ids' => [(string) $hidden->id, (string) $shown->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 1.');

        expect($hidden->refresh()->is_published)->toBeTrue()
            ->and($shown->refresh()->is_published)->toBeTrue()
            ->and($other->refresh()->is_published)->toBeFalse()
            ->and(Activity::query()->count())->toBe(1);

        $this->post(route('fastleo.resources.action', ['articles', 'set-is-published-off']), ['ids' => [$hidden->id, $shown->id]])
            ->assertSessionHas('fastleo.success', 'Изменено: 2.');

        expect($hidden->refresh()->is_published)->toBeFalse()->and($shown->refresh()->is_published)->toBeFalse();
    });

    it('works on one record and respects the update right', function (): void {
        $article = Article::factory()->create(['is_published' => false]);
        Gate::policy(Article::class, NoUpdateArticlePolicy::class);

        $this->post(route('fastleo.resources.action', ['articles', 'set-is-published-on']), ['ids' => [$article->id]])->assertForbidden();

        expect($article->refresh()->is_published)->toBeFalse();
    });

    it('explains that the field is not a checkbox', function (): void {
        $article = Article::factory()->create();

        $this->withoutExceptionHandling()
            ->post(route('fastleo.resources.action', ['broken-toggle', 'set-title-on']), ['ids' => [$article->id]]);
    })->throws(LogicException::class, 'нет поля «title» типа Checkbox');
});
