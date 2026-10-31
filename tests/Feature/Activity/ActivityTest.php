<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Activity\ActivityRecorder;
use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Activity\QuietTopicResource;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->me = admin(['name' => 'Анна', 'email' => 'anna@example.com']);
    $this->actingAs($this->me, Fastleo::GUARD);
});

describe('recording', function (): void {
    it('records who created a record and with what values', function (): void {
        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Новости'])->assertSessionHasNoErrors();

        $entry = Activity::query()->sole();
        expect($entry->event)->toBe('created')
            ->and($entry->resource)->toBe('topics')
            ->and($entry->subject_label)->toBe('Новости')
            ->and($entry->user_name)->toBe('Анна')
            ->and($entry->user_id)->toBe((string) $this->me->id)
            ->and($entry->changes['title'])->toBeStoredJson(['old' => null, 'new' => 'Новости'])
            ->and($entry->changes)->not->toHaveKeys(['id', 'created_at', 'updated_at']);
    });

    it('records only the changed fields with old and new values', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        Activity::query()->delete();

        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Стало'])->assertSessionHasNoErrors();

        $entry = Activity::query()->sole();
        expect($entry->event)->toBe('updated')
            ->and($entry->subject_id)->toBe((string) $topic->id)
            ->and($entry->changes)->toBeStoredJson(['title' => ['old' => 'Было', 'new' => 'Стало']]);
    });

    it('writes nothing when a save changes nothing', function (): void {
        $topic = Topic::factory()->create(['title' => 'Так же']);
        Activity::query()->delete();

        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Так же']);

        expect(Activity::query()->count())->toBe(0);
    });

    it('records a soft delete and a restore from the trash', function (): void {
        $article = Article::factory()->create();
        Activity::query()->delete();

        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]));
        $this->post(route('fastleo.resources.restore', ['articles', $article->id]));

        expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['deleted', 'restored']);
        expect(Activity::query()->where('event', 'deleted')->sole()->snapshot)->toBeNull();
    });

    it('records a permanent delete with a copy of the record', function (): void {
        $topic = Topic::factory()->create(['title' => 'Навсегда']);
        Activity::query()->delete();

        $this->delete(route('fastleo.resources.destroy', ['topics', $topic->id]));

        $entry = Activity::query()->sole();
        expect($entry->event)->toBe('deleted')
            ->and($entry->snapshot['title'])->toBe('Навсегда')
            ->and($entry->snapshot['id'])->toBe($topic->id);
    });

    it('records a force delete of a trashed record with a copy', function (): void {
        $article = Article::factory()->create(['title' => 'Уйдёт']);
        $article->delete();
        Activity::query()->delete();

        $this->delete(route('fastleo.resources.force-delete', ['articles', $article->id]));

        $entry = Activity::query()->sole();
        expect($entry->event)->toBe('force_deleted')->and($entry->snapshot['title'])->toBe('Уйдёт');
    });

    it('does not record what site code does outside an admin request', function (): void {
        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Из админки']);
        Activity::query()->delete();

        Topic::factory()->create();
        Topic::query()->first()?->update(['title' => 'Из кода сайта']);

        expect(Activity::query()->count())->toBe(0);
    });

    it('still writes the journal when the section left the registry after the sections were mapped', function (): void {
        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Первая']);
        useFixtureResources('Registry/TakenBuiltIn');
        Activity::query()->delete();

        $recorder = resolve(ActivityRecorder::class);
        $recorder->start();
        Topic::factory()->create(['title' => 'Вторая']);
        $recorder->stop();

        expect(Activity::query()->sole()->resource)->toBe('topics');
    });

    it('does not write an import summary for a section that keeps no journal', function (): void {
        resolve(ActivityRecorder::class)->importFinished(new QuietTopicResource, ['created' => 1, 'updated' => 0, 'skipped' => 0, 'file' => 'topics.xlsx']);

        expect(Activity::query()->count())->toBe(0);
    });

    it('ignores models that no section describes', function (): void {
        $this->get(route('fastleo.dashboard'));

        User::factory()->create();

        expect(Activity::query()->count())->toBe(0);
    });

    it('keeps passwords and other hidden attributes out of the journal', function (): void {
        $boris = admin(['name' => 'Борис', 'email' => 'boris@example.com']);
        Activity::query()->delete();

        $this->put(route('fastleo.resources.update', ['users', $boris->id]), [
            'name' => 'Борис',
            'email' => 'boris@example.com',
            'password' => 'brand-new-secret',
            'password_confirmation' => 'brand-new-secret',
            'fastleo_admin' => '1',
        ]);

        $entry = Activity::query()->sole();
        expect($entry->changes['password'])->toBe(['hidden' => true]);
        expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('brand-new-secret')->not->toContain($boris->password);
    });

    it('keeps a password field with a custom name out of the journal', function (): void {
        createSecretNotesTable();
        useFixtureResources('Secrets');

        $this->post(route('fastleo.resources.store', 'secret-notes'), ['title' => 'Заметка', 'pin' => 'top-secret-pin'])->assertSessionHasNoErrors();

        $entry = Activity::query()->sole();
        expect($entry->changes['pin'])->toBe(['hidden' => true])
            ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('top-secret-pin');
    });

    it('keeps hidden attributes out of the copy of a deleted record', function (): void {
        $boris = admin(['name' => 'Борис']);
        Activity::query()->delete();

        $this->delete(route('fastleo.resources.destroy', ['users', $boris->id]));

        expect(Activity::query()->sole()->snapshot)->not->toHaveKey('password');
    });

    it('can be switched off in the config', function (): void {
        config(['fastleo.activity.enabled' => false]);

        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Тихо']);

        expect(Activity::query()->count())->toBe(0);
        $this->get(route('fastleo.activity.index'))->assertForbidden();
        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.activity.index'), false);
    });

    it('is rolled back together with a failed save', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), articleData(['title' => '']));

        expect(Activity::query()->count())->toBe(0);
    });
});

describe('revert', function (): void {
    it('returns the old values and records the revert', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Стало']);
        $entry = Activity::query()->where('event', 'updated')->sole();

        $this->post(route('fastleo.activity.revert', $entry->id))
            ->assertRedirect()
            ->assertSessionHas('fastleo.success', 'Готово: запись возвращена.');

        expect($topic->refresh()->title)->toBe('Было')
            ->and(Activity::query()->latest('id')->first()?->event)->toBe('reverted');
    });

    it('can revert the revert', function (): void {
        $topic = Topic::factory()->create(['title' => 'Раз']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Два']);
        $this->post(route('fastleo.activity.revert', Activity::query()->where('event', 'updated')->sole()->id));
        $revert = Activity::query()->where('event', 'reverted')->sole();

        $this->post(route('fastleo.activity.revert', $revert->id))->assertSessionHas('fastleo.success');

        expect($topic->refresh()->title)->toBe('Два');
    });

    it('says so when the values are already the old ones', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Стало']);
        $entry = Activity::query()->where('event', 'updated')->sole();
        Topic::query()->whereKey($topic->id)->update(['title' => 'Было']);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Значения уже такие, как были.');
    });

    it('refuses to return a parent that would make a cycle in the tree', function (): void {
        $a = Category::factory()->create(['title' => 'А']);
        $b = Category::factory()->create(['title' => 'Б']);
        $this->put(route('fastleo.resources.update', ['categories', $b->id]), ['title' => 'Б', 'parent_id' => $a->id])->assertSessionHasNoErrors();
        $this->put(route('fastleo.resources.update', ['categories', $b->id]), ['title' => 'Б', 'parent_id' => ''])->assertSessionHasNoErrors();
        $this->put(route('fastleo.resources.update', ['categories', $a->id]), ['title' => 'А', 'parent_id' => $b->id])->assertSessionHasNoErrors();
        $movedOut = Activity::query()->where('subject_id', (string) $b->id)->where('event', 'updated')->latest('id')->firstOrFail();

        $this->post(route('fastleo.activity.revert', $movedOut->id))->assertSessionHas('fastleo.error', 'Нельзя перенести запись внутрь неё самой.');

        expect($b->refresh()->parent_id)->toBeNull()
            ->and($a->refresh()->parent_id)->toBe($b->id);
    });

    it('says so when the record is gone', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Стало']);
        $entry = Activity::query()->where('event', 'updated')->sole();
        $topic->delete();

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Записи уже нет (или она в корзине).');
    });

    it('does not return fields the section does not let you edit any more', function (): void {
        $article = Article::factory()->create(['title' => 'Старый заголовок', 'views' => 1]);
        $entry = Activity::query()->create([
            'resource' => 'articles', 'subject_id' => (string) $article->id, 'event' => 'updated', 'created_at' => now(),
            'changes' => ['internal_note' => ['old' => 'подмена', 'new' => 'x'], 'password' => ['hidden' => true], 'deleted_at' => ['old' => null, 'new' => 'x']],
        ]);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Нечего возвращать: изменённые поля больше нельзя редактировать в этом разделе.');
    });

    it('recreates a permanently deleted record with its old key', function (): void {
        $topic = Topic::factory()->create(['title' => 'Вернись']);
        $this->delete(route('fastleo.resources.destroy', ['topics', $topic->id]));
        $entry = Activity::query()->where('event', 'deleted')->sole();

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.success');

        expect(Topic::query()->find($topic->id)?->title)->toBe('Вернись');
        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Запись с таким номером уже существует.');
    });

    it('restores a record from the trash', function (): void {
        $article = Article::factory()->create();
        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]));
        $entry = Activity::query()->where('event', 'deleted')->sole();

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.success');
        $this->assertNotSoftDeleted($article);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Значения уже такие, как были.');
    });

    it('recreates a force deleted record from the copy', function (): void {
        $article = Article::factory()->create(['title' => 'Навсегда']);
        $article->delete();
        $this->delete(route('fastleo.resources.force-delete', ['articles', $article->id]));
        $entry = Activity::query()->where('event', 'force_deleted')->sole();

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.success');
        $this->assertNotSoftDeleted($article);

        expect(Article::query()->find($article->id)?->title)->toBe('Навсегда');
    });

    it('says so when a record to return from the trash is gone for good', function (): void {
        $article = Article::factory()->create();
        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]));
        $entry = Activity::query()->where('event', 'deleted')->sole();
        Article::withTrashed()->findOrFail($article->id)->forceDelete();

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Записи уже нет (или она в корзине).');
    });

    it('reports a revert the model cancelled when recreating a record', function (): void {
        $topic = Topic::factory()->create(['title' => 'Вернись']);
        $this->delete(route('fastleo.resources.destroy', ['topics', $topic->id]));
        $entry = Activity::query()->where('event', 'deleted')->sole();
        Topic::creating(fn (): bool => false);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Сайт отменил возврат.');

        expect(Topic::query()->find($topic->id))->toBeNull();
    });

    it('reports a revert the model cancelled when restoring from the trash', function (): void {
        $article = Article::factory()->create();
        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]));
        $entry = Activity::query()->where('event', 'deleted')->sole();
        Article::restoring(fn (): bool => false);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error', 'Сайт отменил возврат.');

        $this->assertSoftDeleted($article);
    });

    it('writes the return of a deleted record as a creation that cannot be reverted', function (): void {
        $topic = Topic::factory()->create(['title' => 'Вернись']);
        $this->delete(route('fastleo.resources.destroy', ['topics', $topic->id]));
        $this->post(route('fastleo.activity.revert', Activity::query()->where('event', 'deleted')->sole()->id));

        $created = Activity::query()->where('event', 'created')->sole();
        expect(Activity::query()->where('event', 'reverted')->count())->toBe(0);

        $this->post(route('fastleo.activity.revert', $created->id))->assertSessionHas('fastleo.error', 'Это событие откатить нельзя.');
        $this->get(route('fastleo.activity.index'))->assertOk();
        expect($topic->refresh()->title)->toBe('Вернись');
    });

    it('writes the return of a record from the trash as a restoration that cannot be reverted', function (): void {
        $article = Article::factory()->create(['title' => 'Из корзины']);
        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]));
        $this->post(route('fastleo.activity.revert', Activity::query()->where('event', 'deleted')->sole()->id));

        $restored = Activity::query()->where('event', 'restored')->sole();
        expect(Activity::query()->where('event', 'reverted')->count())->toBe(0);

        $this->post(route('fastleo.activity.revert', $restored->id))->assertSessionHas('fastleo.error', 'Это событие откатить нельзя.');
        expect($article->refresh()->trashed())->toBeFalse();
    });

    it('does not revert a creation or an import', function (): void {
        $created = Activity::query()->create(['resource' => 'topics', 'subject_id' => '1', 'event' => 'created', 'created_at' => now()]);

        $this->post(route('fastleo.activity.revert', $created->id))->assertSessionHas('fastleo.error', 'Это событие откатить нельзя.');
    });

    it('needs the update right and does not revert for a forbidden section', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Стало']);
        $entry = Activity::query()->where('event', 'updated')->sole();

        Gate::policy(Topic::class, HiddenCategoryPolicy::class);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertForbidden();
        expect($topic->refresh()->title)->toBe('Стало');
    });

    it('turns down an unknown entry and a section without a journal', function (): void {
        $this->post(route('fastleo.activity.revert', 99999))->assertNotFound();

        $entry = Activity::query()->create(['resource' => 'unknown', 'subject_id' => '1', 'event' => 'updated', 'created_at' => now()]);
        $this->post(route('fastleo.activity.revert', $entry->id))->assertNotFound();
    });

    it('refuses a revert that would break a database rule with an explanation', function (): void {
        $article = Article::factory()->create(['title' => 'Старый']);
        $entry = Activity::query()->create([
            'resource' => 'articles', 'subject_id' => (string) $article->id, 'event' => 'updated', 'created_at' => now(),
            'changes' => ['title' => ['old' => null, 'new' => 'Старый']],
        ]);

        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.error');
        expect($article->refresh()->title)->toBe('Старый');
    });
});

describe('pages', function (): void {
    it('shows the journal with sections the admin may open, filters and the menu link', function (): void {
        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Новости']);
        $this->post(route('fastleo.resources.store', 'articles'), articleData(['title' => 'Статья']));

        $this->get(route('fastleo.dashboard'))->assertSee(route('fastleo.activity.index'), false);
        $this->get(route('fastleo.activity.index'))->assertOk()->assertSee('Новости')->assertSee('Статья')->assertSee('Создано');
        $this->get(route('fastleo.activity.index', ['resource' => 'topics']))->assertSee('Новости')->assertDontSee('Статья');
        $this->get(route('fastleo.activity.index', ['user' => 'nobody']))->assertDontSee('Новости');
        $this->get(route('fastleo.activity.index', ['resource' => ['x'], 'user' => ['y']]))->assertOk();
    });

    it('hides sections the admin has no right to list', function (): void {
        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Секретная тема']);
        Gate::policy(Topic::class, HiddenCategoryPolicy::class);

        $this->get(route('fastleo.activity.index'))->assertOk()->assertDontSee('Секретная тема');
        $this->get(route('fastleo.resources.history', ['topics', Topic::query()->firstOrFail()->id]))->assertForbidden();
    });

    it('can be closed with the viewFastleoActivity gate', function (): void {
        Gate::define('viewFastleoActivity', fn (User $user): bool => false);

        $this->get(route('fastleo.activity.index'))->assertForbidden();
        $this->get(route('fastleo.dashboard'))->assertDontSee(route('fastleo.activity.index'), false);

        Gate::define('viewFastleoActivity', fn (User $user): bool => $user->is($this->me));
        $this->get(route('fastleo.activity.index'))->assertOk();
    });

    it('shows the history of a record with revert buttons and a link from the edit page', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => 'Стало']);

        $this->get(route('fastleo.resources.edit', ['topics', $topic->id]))->assertSee(route('fastleo.resources.history', ['topics', $topic->id]), false);
        $this->get(route('fastleo.resources.show', ['topics', $topic->id]))->assertSee(route('fastleo.resources.history', ['topics', $topic->id]), false);
        $this->get(route('fastleo.resources.history', ['topics', $topic->id]))
            ->assertOk()
            ->assertSee('Название')
            ->assertSee('Было')
            ->assertSee('Стало')
            ->assertSee('data-activity-revert', false);
    });

    it('shows the history of a deleted record and does not offer revert without the right', function (): void {
        $topic = Topic::factory()->create(['title' => 'Удалённая']);
        $this->delete(route('fastleo.resources.destroy', ['topics', $topic->id]));

        $this->get(route('fastleo.resources.history', ['topics', $topic->id]))
            ->assertOk()
            ->assertSee('запись удалена')
            ->assertSee('Восстановить');
    });

    it('answers 404 for a record that never existed and for a bad key', function (): void {
        $this->get(route('fastleo.resources.history', ['topics', 99999]))->assertNotFound();
        $this->get(route('fastleo.resources.history', ['nothing', 1]))->assertNotFound();
        $this->get(route('fastleo.resources.history', ['topics', 'a_b']))->assertNotFound();
    });

    it('does not offer the history for a section that keeps no journal', function (): void {
        config(['fastleo.activity.enabled' => false]);
        $topic = Topic::factory()->create();

        $this->get(route('fastleo.resources.edit', ['topics', $topic->id]))->assertDontSee('/history', false);
        $this->get(route('fastleo.resources.history', ['topics', $topic->id]))->assertNotFound();
    });

    it('pages through a long journal', function (): void {
        foreach (range(1, 60) as $number) {
            Activity::query()->create(['resource' => 'topics', 'subject_id' => (string) $number, 'event' => 'created', 'subject_label' => 'Тема '.$number, 'created_at' => now()]);
        }

        $this->get(route('fastleo.activity.index'))->assertSee('Тема 60')->assertDontSee('Тема 5<')->assertSee('Вперёд');
        $this->get(route('fastleo.activity.index', ['page' => 2]))->assertSee('Назад');
    });
});

describe('pruning', function (): void {
    it('deletes entries older than the keep period', function (): void {
        Activity::query()->create(['resource' => 'topics', 'subject_id' => '1', 'event' => 'created', 'created_at' => now()->subDays(40)]);
        Activity::query()->create(['resource' => 'topics', 'subject_id' => '2', 'event' => 'created', 'created_at' => now()->subDays(5)]);

        $this->artisan('fastleo:prune')->expectsOutputToContain('Удалено устаревших записей: 1.')->assertSuccessful();

        expect(Activity::query()->pluck('subject_id')->all())->toBe(['2']);
    });

    it('honours the configured period', function (): void {
        config(['fastleo.activity.keep_days' => 3]);
        Activity::query()->create(['resource' => 'topics', 'subject_id' => '1', 'event' => 'created', 'created_at' => now()->subDays(5)]);

        $this->artisan('fastleo:prune')->assertSuccessful();

        expect(Activity::query()->count())->toBe(0);
    });
});

describe('safety', function (): void {
    it('shows values as plain text, never as markup', function (): void {
        $topic = Topic::factory()->create(['title' => 'Было']);
        $this->put(route('fastleo.resources.update', ['topics', $topic->id]), ['title' => '<script>alert(1)</script><b>жирно</b>']);

        $this->get(route('fastleo.resources.history', ['topics', $topic->id]))
            ->assertOk()
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('<b>жирно', false)
            ->assertSee('alert(1)')
            ->assertSee('жирно');
    });

    it('does not keep a journal for a section that switched it off', function (): void {
        useFixtureResources('Activity');

        $this->post(route('fastleo.resources.store', 'topics'), ['title' => 'Тихо'])->assertSessionHasNoErrors();

        expect(Activity::query()->count())->toBe(0);
        $this->get(route('fastleo.resources.history', ['topics', Topic::query()->firstOrFail()->id]))->assertNotFound();
    });

    it('does not recreate a record that the section list would not show', function (): void {
        useFixtureResources('ActivityScoped');
        $entry = Activity::query()->create([
            'resource' => 'topics', 'subject_id' => '777', 'event' => 'deleted', 'created_at' => now(),
            'snapshot' => ['id' => 777, 'title' => 'Скрытая тема', 'sort' => 0],
        ]);

        $this->post(route('fastleo.activity.revert', $entry->id))
            ->assertSessionHas('fastleo.error', 'Возвращённая запись не попадает в ваш список раздела, возврат отменён.');
        expect(Topic::query()->find(777))->toBeNull();

        $entry->update(['snapshot' => ['id' => 778, 'title' => 'Видимая тема', 'sort' => 0], 'subject_id' => '778']);
        $this->post(route('fastleo.activity.revert', $entry->id))->assertSessionHas('fastleo.success');
        expect(Topic::query()->find(778)?->title)->toBe('Видимая тема');
    });
});

describe('access to the entries', function (): void {
    beforeEach(function (): void {
        useFixtureResources('ActivityScoped');
        $this->visible = Topic::query()->create(['title' => 'Видимая тема']);
        $this->secret = Topic::query()->create(['title' => 'Скрытая тема']);
    });

    it('does not show the history of a record outside the section selection', function (): void {
        Activity::query()->create(['resource' => 'topics', 'subject_id' => (string) $this->secret->id, 'subject_label' => 'Скрытая тема', 'event' => 'updated', 'created_at' => now(), 'changes' => ['title' => ['old' => 'СЕКРЕТ-СТАРОЕ', 'new' => 'Скрытая тема']]]);

        $this->get(route('fastleo.resources.history', ['topics', $this->secret->id]))->assertNotFound();
    });

    it('keeps the history of a record that is gone for good', function (): void {
        Activity::query()->create(['resource' => 'topics', 'subject_id' => '999', 'subject_label' => 'Удалённая', 'event' => 'deleted', 'created_at' => now(), 'changes' => ['title' => ['old' => 'Удалённая', 'new' => null]]]);

        $this->get(route('fastleo.resources.history', ['topics', 999]))->assertOk()->assertSee('Удалённая');
    });

    it('hides the label and the values of a record outside the selection in the common journal', function (): void {
        Activity::query()->create(['resource' => 'topics', 'subject_id' => (string) $this->secret->id, 'subject_label' => 'Скрытая тема', 'event' => 'updated', 'created_at' => now(), 'changes' => ['title' => ['old' => 'СЕКРЕТ-СТАРОЕ', 'new' => 'Скрытая тема']]]);
        Activity::query()->create(['resource' => 'topics', 'subject_id' => (string) $this->visible->id, 'subject_label' => 'Видимая тема', 'event' => 'updated', 'created_at' => now(), 'changes' => ['title' => ['old' => 'ВИДНО-СТАРОЕ', 'new' => 'Видимая тема']]]);
        Activity::query()->create(['resource' => 'topics', 'subject_id' => '999', 'subject_label' => 'Удалённая', 'event' => 'deleted', 'created_at' => now(), 'changes' => ['title' => ['old' => 'Удалённая', 'new' => null]]]);

        $this->get(route('fastleo.activity.index'))
            ->assertOk()
            ->assertDontSee('СЕКРЕТ-СТАРОЕ')
            ->assertDontSee('Скрытая тема')
            ->assertSee('data-activity-restricted', false)
            ->assertSee('ВИДНО-СТАРОЕ')
            ->assertSee('Удалённая');
    });

    it('does not show the history of a record hidden by a global scope of the model', function (): void {
        useFixtureResources('ActivityTenant');
        $foreign = Topic::query()->create(['title' => 'Чужая тема']);
        Activity::query()->create(['resource' => 'topics', 'subject_id' => (string) $foreign->id, 'subject_label' => 'Чужая тема', 'event' => 'updated', 'created_at' => now(), 'changes' => ['title' => ['old' => 'ЧУЖОЕ-СТАРОЕ', 'new' => 'Чужая тема']]]);

        $this->get(route('fastleo.resources.history', ['topics', $foreign->id]))->assertNotFound();
        $this->get(route('fastleo.activity.index'))
            ->assertOk()
            ->assertDontSee('ЧУЖОЕ-СТАРОЕ')
            ->assertDontSee('Чужая тема')
            ->assertSee('data-activity-restricted', false);
    });

    it('does not print the values of columns that are not fields of the section', function (): void {
        Activity::query()->create(['resource' => 'topics', 'subject_id' => (string) $this->visible->id, 'subject_label' => 'Видимая тема', 'event' => 'updated', 'created_at' => now(), 'changes' => ['sort' => ['old' => 4242, 'new' => 5353], 'title' => ['old' => 'Было', 'new' => 'Стало']]]);

        $this->get(route('fastleo.resources.history', ['topics', $this->visible->id]))
            ->assertOk()
            ->assertDontSee('4242')
            ->assertDontSee('5353')
            ->assertSee('sort')
            ->assertSee('Было');
    });
});
