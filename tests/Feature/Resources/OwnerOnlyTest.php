<?php

declare(strict_types=1);

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Layout\Layout;
use Fastleo\Resources\UserResource;
use Fastleo\Support\Authorization;
use Fastleo\Tests\Fixtures\OwnerOnly\ArticleResource;
use Fastleo\Tests\Fixtures\Policies\AllowEverythingArticlePolicy;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * Войти под другим пользователем в том же тесте: сбросить guard и сессию.
 */
function signInAs(object $test, User $user): void
{
    resolve('auth')->forgetGuards();
    $test->flushSession();
    $test->actingAs($user, Fastleo::GUARD);
}

beforeEach(function (): void {
    useFixtureResources('OwnerOnly');
    $this->owner = admin(['email' => 'owner@example.com']);
    $this->manager = admin(['email' => 'manager@example.com']);
    Fastleo::resolveOwnerUsing(fn (User $user): bool => $user->email === 'owner@example.com');
    signInAs($this, $this->manager);
});

afterEach(function (): void {
    Fastleo::resolveOwnerUsing(null);
    ArticleResource::$ownerOnly = [];
    ArticleResource::$ownerOnlyIndex = false;
});

describe('who is the owner', function (): void {
    it('asks a failing resolver once per user and reports the failure once', function (): void {
        Exceptions::fake();
        $calls = 0;
        Fastleo::resolveOwnerUsing(function () use (&$calls): bool {
            $calls++;

            throw new RuntimeException('boom');
        });

        expect(Fastleo::isOwner())->toBeFalse()->and(Fastleo::isOwner())->toBeFalse()->and($calls)->toBe(1);
        Exceptions::assertReportedCount(1);
    });

    it('is nobody until the site says so', function (): void {
        Fastleo::resolveOwnerUsing(null);

        expect(Fastleo::hasOwnerResolver())->toBeFalse()
            ->and(Fastleo::isOwner($this->owner))->toBeFalse()
            ->and(RoleMatrix::allowsOwnerOnly())->toBeTrue();
    });

    it('is the user the resolver names, for the signed in one or for the given one', function (): void {
        expect(Fastleo::hasOwnerResolver())->toBeTrue()
            ->and(Fastleo::isOwner())->toBeFalse()
            ->and(Fastleo::isOwner($this->owner))->toBeTrue()
            ->and(RoleMatrix::allowsOwnerOnly())->toBeFalse();

        signInAs($this, $this->owner);

        expect(Fastleo::isOwner())->toBeTrue()->and(RoleMatrix::isOwner())->toBeTrue()->and(RoleMatrix::allowsOwnerOnly())->toBeTrue();
    });

    it('is nobody without a signed in user, in the console or the queue', function (): void {
        resolve('auth')->forgetGuards();
        $this->flushSession();

        expect(Fastleo::isOwner())->toBeFalse();
    });

    it('counts only a strict true of the resolver', function (mixed $answer): void {
        Fastleo::resolveOwnerUsing(fn (): mixed => $answer);

        expect(Fastleo::isOwner($this->owner))->toBeFalse();
    })->with(['string zero' => '0', 'string' => 'yes', 'int' => 1, 'null' => null, 'false' => false]);

    it('is nobody and reports it when the resolver throws', function (): void {
        Exceptions::fake();
        Fastleo::resolveOwnerUsing(fn (): never => throw new RuntimeException('нет таблицы'));

        expect(Fastleo::isOwner($this->owner))->toBeFalse();
        Exceptions::assertReported(RuntimeException::class);
    });

    it('asks the resolver once per user object, and again for another object or after it is replaced', function (): void {
        $calls = 0;
        Fastleo::resolveOwnerUsing(function (User $user) use (&$calls): bool {
            $calls++;

            return $user->email === 'owner@example.com';
        });

        Fastleo::isOwner($this->owner);
        Fastleo::isOwner($this->owner);
        expect($calls)->toBe(1);

        Fastleo::isOwner(User::query()->findOrFail($this->owner->id));
        expect($calls)->toBe(2);

        Fastleo::resolveOwnerUsing(function () use (&$calls): bool {
            $calls++;

            return true;
        });
        Fastleo::isOwner($this->owner);
        expect($calls)->toBe(3);
    });

    it('is decided by the role when roles are on: the resolver does not count', function (): void {
        config(['fastleo.roles.enabled' => true]);

        expect(Fastleo::isOwner($this->owner))->toBeFalse();
    });

    it('shuts the pages that are for the owner only from the others', function (): void {
        $this->get(route('fastleo.files.index'))->assertForbidden();
        $this->get(route('fastleo.logs.index'))->assertForbidden();
        expect(new UserResource()->can('viewAny', null))->toBeFalse();

        signInAs($this, $this->owner);

        $this->get(route('fastleo.files.index'))->assertOk();
        $this->get(route('fastleo.logs.index'))->assertOk();
        expect(new UserResource()->can('viewAny', null))->toBeTrue();
    });

    it('shows who the owner is in the list of users, while the site names the owners itself', function (): void {
        $names = fn (): array => array_map(fn (\Fastleo\Fields\Field|Layout $field): string => $field->name, new UserResource()->fields());
        $owner = collect(new UserResource()->fields())->first(fn ($field): bool => $field->name === 'owner');

        expect($names())->toContain('owner')
            ->and($owner->display($this->owner))->toBe('Владелец')
            ->and($owner->display($this->manager))->toBe('—')
            ->and($owner->display(new Article()))->toBe('—')
            ->and($owner->isComputed())->toBeTrue();

        Fastleo::resolveOwnerUsing(null);
        expect($names())->not->toContain('owner');
    });
});

describe('what a section closes to the others', function (): void {
    it('closes deletion with the group delete: both kinds of it', function (): void {
        ArticleResource::$ownerOnly = ['delete'];
        $article = Article::factory()->create();
        $resource = new ArticleResource();

        expect(Authorization::allows('delete', $resource, $article))->toBeFalse()
            ->and(Authorization::allows('forceDelete', $resource, $article))->toBeFalse()
            ->and(Authorization::allows('update', $resource, $article))->toBeTrue()
            ->and(Authorization::allows('viewAny', $resource))->toBeTrue();
        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))->assertForbidden();

        signInAs($this, $this->owner);

        expect(Authorization::allows('forceDelete', $resource, $article))->toBeTrue();
        $this->delete(route('fastleo.resources.destroy', ['articles', $article->id]))->assertRedirect();
        expect(Article::query()->find($article->id))->toBeNull();
    });

    it('closes order, restore and own actions with the group update', function (): void {
        ArticleResource::$ownerOnly = ['update'];
        $article = Article::factory()->create();
        $resource = new ArticleResource();

        expect(Authorization::deniedToNonOwner('update', $resource))->toBeTrue()
            ->and(Authorization::deniedToNonOwner('reorder', $resource))->toBeTrue()
            ->and(Authorization::deniedToNonOwner('restore', $resource))->toBeTrue()
            ->and(Authorization::deniedToNonOwner('publish', $resource))->toBeTrue()
            ->and(Authorization::deniedToNonOwner('create', $resource))->toBeFalse()
            ->and(Authorization::allowsReorder($resource, $article))->toBeFalse();
    });

    it('closes creation and import with the group create', function (): void {
        ArticleResource::$ownerOnly = ['create'];
        $resource = new ArticleResource();

        expect(Authorization::allows('create', $resource))->toBeFalse()
            ->and(Authorization::allowsOwnOr('import', 'create', $resource))->toBeFalse()
            ->and(Authorization::allows('update', $resource, Article::factory()->create()))->toBeTrue();
        $this->get(route('fastleo.resources.create', 'articles'))->assertForbidden();
    });

    it('closes only the export with the ability export, and keeps the list', function (): void {
        ArticleResource::$ownerOnly = ['export'];
        $resource = new ArticleResource();

        expect(Authorization::allowsOwnOr('export', 'viewAny', $resource))->toBeFalse()
            ->and(Authorization::allows('viewAny', $resource))->toBeTrue();
        $this->get(route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'csv']))->assertForbidden();

        signInAs($this, $this->owner);

        $this->get(route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'csv']))->assertOk();
    });

    it('is not got round by a Policy that has its own methods export, import and reorder', function (): void {
        Gate::policy(Article::class, AllowEverythingArticlePolicy::class);
        $article = Article::factory()->create();
        $resource = new ArticleResource();

        ArticleResource::$ownerOnly = ['export', 'import', 'update'];

        expect(Authorization::allowsOwnOr('export', 'viewAny', $resource))->toBeFalse()
            ->and(Authorization::allowsOwnOr('import', 'create', $resource))->toBeFalse()
            ->and(Authorization::allowsReorder($resource, $article))->toBeFalse()
            ->and(Authorization::allows('viewAny', $resource))->toBeTrue();

        signInAs($this, $this->owner);

        expect(Authorization::allowsOwnOr('export', 'viewAny', $resource))->toBeTrue()
            ->and(Authorization::allowsReorder($resource, $article))->toBeTrue();
    });

    it('closes the whole section with the star', function (): void {
        ArticleResource::$ownerOnly = ['*'];

        $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();
        $this->get(route('fastleo.resources.create', 'articles'))->assertForbidden();

        signInAs($this, $this->owner);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk();
    });

    it('closes the shared list but not a table of the section on the page of another record', function (): void {
        ArticleResource::$ownerOnlyIndex = true;
        $resource = new ArticleResource();

        expect(Authorization::allows('viewAny', $resource))->toBeFalse();
        $this->get(route('fastleo.resources.index', 'articles'))->assertForbidden();

        signInAs($this, $this->owner);

        $this->get(route('fastleo.resources.index', 'articles'))->assertOk();
    });

    it('closes the export, the import and the links to a shared list that only the owner may open', function (): void {
        ArticleResource::$ownerOnlyIndex = true;
        $resource = new ArticleResource();

        $this->get(route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'csv']))->assertForbidden();
        $this->get(route('fastleo.search', ['q' => 'a']))->assertOk();
        expect(Authorization::allowsIndexLink($resource))->toBeFalse()
            ->and(Authorization::allowsOwnOr('export', 'viewAny', $resource))->toBeFalse();

        signInAs($this, $this->owner);

        expect(Authorization::allowsIndexLink($resource))->toBeTrue();
        $this->get(route('fastleo.resources.export', ['resource' => 'articles', 'format' => 'csv']))->assertOk();
    });

    it('shuts the section for everybody when the site has no owner at all', function (): void {
        Fastleo::resolveOwnerUsing(null);
        ArticleResource::$ownerOnly = ['delete'];
        $resource = new ArticleResource();

        expect(Authorization::allows('delete', $resource, Article::factory()->create()))->toBeFalse();

        signInAs($this, $this->owner);

        expect(Authorization::allows('delete', $resource, Article::factory()->create()))->toBeFalse();
    });

    it('does not give the titles of a section that is for the owner only to the fields of others', function (): void {
        $related = new Category();

        expect(RoleMatrix::allowsRelated($related))->toBeFalse()
            ->and(RoleMatrix::closesRelated($related))->toBeTrue();

        signInAs($this, $this->owner);

        expect(RoleMatrix::allowsRelated($related))->toBeTrue()->and(RoleMatrix::closesRelated($related))->toBeFalse();
    });

    it('turns a field on a section for the owner only into a read-only one without roles too', function (): void {
        $field = BelongsTo::make('category_id', 'Категория')->forModel(Article::class);

        expect($field->isReadonly())->toBeTrue()->and($field->isWritable())->toBeFalse()->and($field->getHelp())->toContain('Нет доступа к разделу');

        signInAs($this, $this->owner);

        expect($field->isReadonly())->toBeFalse()->and($field->isWritable())->toBeTrue();
    });

    it('lets the unlimited sections be seen as before', function (): void {
        expect(RoleMatrix::allowsRelated(new Article()))->toBeTrue();
    });
});
