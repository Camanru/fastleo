<?php

declare(strict_types=1);

use Fastleo\Activity\Activity;
use Fastleo\Activity\ActivityRecorder;
use Fastleo\Fastleo;
use Fastleo\Mcp\FastleoServer;
use Fastleo\Mcp\Tools\CreateRecord;
use Fastleo\Mcp\Tools\DeleteRecord;
use Fastleo\Mcp\Tools\DescribeResource;
use Fastleo\Mcp\Tools\GetRecord;
use Fastleo\Mcp\Tools\ListRecords;
use Fastleo\Mcp\Tools\ListResources;
use Fastleo\Mcp\Tools\RunAction;
use Fastleo\Mcp\Tools\UpdateRecord;
use Fastleo\Resources\UserResource;
use Fastleo\Tests\Fixtures\McpUsers\OpenUserResource;
use Fastleo\Tests\Fixtures\Policies\HiddenCategoryPolicy;
use Fastleo\Tests\Fixtures\Policies\RestrictedCategoryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Page;
use Workbench\App\Models\User;

/**
 * Сервер MCP от имени администратора.
 */
function mcp(?User $user = null): PendingTestResponse
{
    return FastleoServer::actingAs($user ?? admin(), 'fastleo');
}

/**
 * Сайт открыл раздел «Администраторы» агенту.
 */
function openAdminsToAgent(): void
{
    config(['fastleo.users.resource' => OpenUserResource::class]);
    Fastleo::resources()->flush();
}

it('lists the sections the admin may open', function (): void {
    mcp()->tool(ListResources::class)
        ->assertHasNoErrors()
        ->assertSee(['"resource":"articles"', '"resource":"feedback"', '"readonly":true']);
});

it('hides sections the Policy closes for viewing', function (): void {
    Gate::policy(Category::class, HiddenCategoryPolicy::class);

    mcp()->tool(ListResources::class)->assertSee('"resource":"articles"')->assertDontSee('"resource":"categories"');
    mcp()->tool(DescribeResource::class, ['resource' => 'categories'])->assertHasErrors(['Нет права «viewAny»']);
});

it('describes a section with fields, rules and the rights of the admin', function (): void {
    mcp()->tool(DescribeResource::class, ['resource' => 'articles'])
        ->assertHasNoErrors()
        ->assertSee(['"name":"title"', 'required', '"policy"', '"create":true']);
});

it('names the available sections when the address is wrong', function (): void {
    mcp()->tool(DescribeResource::class, ['resource' => 'nothing'])->assertHasErrors(['Раздела «nothing» нет. Доступные адреса:']);
});

it('lists records with search, filters, sorting and paging', function (): void {
    Article::factory()->create(['title' => 'Alpha news', 'status' => 'published']);
    Article::factory()->create(['title' => 'Beta news', 'status' => 'draft']);
    Article::factory()->create(['title' => 'Gamma other', 'status' => 'draft']);

    mcp()->tool(ListRecords::class, ['resource' => 'articles', 'q' => 'news'])
        ->assertSee(['Alpha news', 'Beta news', '"total":2'])->assertDontSee('Gamma other');

    mcp()->tool(ListRecords::class, ['resource' => 'articles', 'filters' => ['status' => 'draft']])
        ->assertSee(['Beta news', 'Gamma other'])->assertDontSee('Alpha news');

    mcp()->tool(ListRecords::class, ['resource' => 'articles', 'sort' => 'title', 'direction' => 'desc', 'per_page' => 1, 'page' => 2])
        ->assertSee(['Beta news', '"total":3', '"page":2'])->assertDontSee(['Alpha news', 'Gamma other']);
});

it('does not sort or filter by anything the section did not allow', function (): void {
    Article::factory()->create(['title' => 'Only one']);

    mcp()->tool(ListRecords::class, ['resource' => 'articles', 'sort' => 'password; drop table', 'filters' => ['id' => '99']])
        ->assertHasNoErrors()->assertSee('Only one');
});

it('rejects a bad page number', function (): void {
    mcp()->tool(ListRecords::class, ['resource' => 'articles', 'page' => 0])->assertHasErrors();
});

it('reads one record with the form values', function (): void {
    $article = Article::factory()->create(['title' => 'Readable']);

    mcp()->tool(GetRecord::class, ['resource' => 'articles', 'id' => (string) $article->id])
        ->assertHasNoErrors()->assertSee(['"title":"Readable"', '"values"']);
});

it('does not give out a record that does not exist or is bad id', function (): void {
    mcp()->tool(GetRecord::class, ['resource' => 'articles', 'id' => '999999'])->assertHasErrors(['Запись не найдена']);
    mcp()->tool(GetRecord::class, ['resource' => 'articles', 'id' => '1 or 1=1'])->assertHasErrors(['Запись не найдена']);
});

it('never reveals passwords of the admin section', function (): void {
    openAdminsToAgent();

    $other = admin(['email' => 'second@example.com']);

    mcp()->tool(GetRecord::class, ['resource' => 'users', 'id' => (string) $other->id])
        ->assertHasNoErrors()->assertSee('second@example.com')->assertDontSee(['"password"', $other->password]);
});

it('creates a record with the checks and defaults of the form', function (): void {
    mcp()->tool(CreateRecord::class, ['resource' => 'articles', 'values' => ['title' => 'From Claude', 'status' => 'draft', 'views' => 5, 'is_published' => true]])
        ->assertHasNoErrors()->assertSee(['Запись сохранена', '"title":"From Claude"']);

    $article = Article::query()->where('title', 'From Claude')->sole();

    expect($article->views)->toBe(5)
        ->and($article->is_published)->toBeTrue()
        ->and($article->type)->toBe('news');
});

it('returns validation errors by field and writes nothing', function (): void {
    mcp()->tool(CreateRecord::class, ['resource' => 'articles', 'values' => ['status' => 'unknown']])
        ->assertHasErrors(['Проверка не пройдена'])->assertSee(['title', 'status']);

    expect(Article::query()->count())->toBe(0);
});

it('refuses values for fields that are not writable', function (): void {
    mcp()->tool(CreateRecord::class, ['resource' => 'articles', 'values' => ['title' => 'x', 'status' => 'draft', 'id' => 99, 'deleted_at' => '2020-01-01']])
        ->assertHasErrors(['Эти поля нельзя записать'])->assertSee(['id', 'deleted_at']);

    expect(Article::query()->count())->toBe(0);
});

it('does not let the agent grant admin access through the users section', function (): void {
    openAdminsToAgent();

    $admin = admin();
    $before = User::query()->count();

    mcp($admin)->tool(CreateRecord::class, ['resource' => 'users', 'values' => ['name' => 'Evil', 'email' => 'evil@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password', 'is_admin' => true]])
        ->assertHasErrors(['Эти поля нельзя записать']);

    expect(User::query()->count())->toBe($before);
});

it('follows the Policy when creating', function (): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);

    mcp()->tool(CreateRecord::class, ['resource' => 'categories', 'values' => ['title' => 'New']])->assertHasErrors(['Нет права «create»']);

    expect(Category::query()->count())->toBe(0);
});

it('asks for the right to open the section before creating a record in it', function (): void {
    Gate::policy(Category::class, HiddenCategoryPolicy::class);

    mcp()->tool(CreateRecord::class, ['resource' => 'categories', 'values' => ['title' => 'New']])->assertHasErrors(['Нет права «viewAny»']);

    expect(Category::query()->count())->toBe(0);
});

it('creates an admin when the agent sends the password once', function (): void {
    openAdminsToAgent();

    mcp()->tool(CreateRecord::class, ['resource' => 'users', 'values' => ['name' => 'Борис', 'email' => 'boris@example.com', 'password' => 'secret-password-1', 'fastleo_admin' => true]])
        ->assertHasNoErrors();

    expect(Hash::check('secret-password-1', (string) User::query()->where('email', 'boris@example.com')->value('password')))->toBeTrue();
});

it('stops serving an admin whose access was taken away after the server started', function (): void {
    $admin = admin();
    $session = mcp($admin);

    $session->tool(ListResources::class)->assertHasNoErrors();
    User::query()->whereKey($admin->id)->update(['fastleo_admin' => false]);

    $session->tool(ListResources::class)->assertHasErrors(['без администратора']);
});

it('checks a dependent field again when only the field it depends on is sent', function (): void {
    useFixtureResources('Dependent');
    $article = Article::factory()->create(['title' => 'Акция', 'type' => 'promo', 'layout' => 'banner', 'phone' => '+7 900 000-00-00', 'is_featured' => false]);

    mcp()->tool(UpdateRecord::class, ['resource' => 'promo-articles', 'id' => (string) $article->id, 'values' => ['type' => 'news']])
        ->assertHasErrors(['layout']);

    expect($article->refresh()->type)->toBe('promo');
});

it('does not write into read-only sections', function (): void {
    mcp()->tool(CreateRecord::class, ['resource' => 'feedback', 'values' => ['name' => 'x']])->assertHasErrors();
});

it('changes only the fields that were sent', function (): void {
    $article = Article::factory()->create(['title' => 'Old title', 'views' => 7, 'status' => 'draft']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'articles', 'id' => (string) $article->id, 'values' => ['title' => 'New title']])
        ->assertHasNoErrors()->assertSee('"title":"New title"');

    $article->refresh();

    expect($article->title)->toBe('New title')
        ->and($article->views)->toBe(7)
        ->and($article->status->value)->toBe('draft');
});

it('keeps the record intact when the check fails', function (): void {
    $article = Article::factory()->create(['title' => 'Stays']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'articles', 'id' => (string) $article->id, 'values' => ['title' => '']])
        ->assertHasErrors(['Проверка не пройдена']);

    expect($article->refresh()->title)->toBe('Stays');
});

it('follows the Policy when updating', function (): void {
    Gate::policy(Category::class, RestrictedCategoryPolicy::class);
    $closed = Category::factory()->create(['title' => 'Закрыто']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'categories', 'id' => (string) $closed->id, 'values' => ['title' => 'Открыто']])
        ->assertHasErrors(['Нет права «update»']);

    expect($closed->refresh()->title)->toBe('Закрыто');
});

it('hides the delete tool unless deletion is enabled', function (): void {
    $article = Article::factory()->create();

    expect(new DeleteRecord()->shouldRegister())->toBeFalse();
    mcp()->tool(DeleteRecord::class, ['resource' => 'articles', 'id' => (string) $article->id])->assertNotRegistered();

    expect(Article::query()->count())->toBe(1);
});

it('deletes only what the Policy allows when deletion is enabled', function (): void {
    config(['fastleo.mcp.allow_delete' => true]);
    $draft = Article::factory()->create(['is_published' => false]);
    $published = Article::factory()->create(['is_published' => true]);

    mcp()->tool(DeleteRecord::class, ['resource' => 'articles', 'id' => (string) $draft->id])->assertHasNoErrors()->assertSee('Запись удалена');
    mcp()->tool(DeleteRecord::class, ['resource' => 'articles', 'id' => (string) $published->id])->assertHasErrors(['Нет права «delete»']);

    expect(Article::query()->pluck('id')->all())->toBe([$published->id])
        ->and(Article::withTrashed()->count())->toBe(2);
});

it('does not delete a record that has nested ones', function (): void {
    config(['fastleo.mcp.allow_delete' => true]);
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);

    mcp()->tool(DeleteRecord::class, ['resource' => 'categories', 'id' => (string) $parent->id])->assertHasErrors(['вложенные']);

    expect(Category::query()->count())->toBe(2);
});

it('does not delete from read-only sections', function (): void {
    config(['fastleo.mcp.allow_delete' => true]);

    mcp()->tool(DeleteRecord::class, ['resource' => 'feedback', 'id' => '1'])->assertHasErrors(['только для чтения']);
});

it('reports a done action whose answer is a file instead of failing', function (): void {
    useFixtureResources('Actions');
    $article = Article::factory()->create(['title' => 'Заголовок']);

    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'export-titles', 'ids' => [(string) $article->id]])
        ->assertHasNoErrors()
        ->assertSee(['Действие выполнено; его ответ (файл или страница) через MCP не передаётся.', '"processed":1']);
});

it('runs an action on the allowed records and reports the skipped ones', function (): void {
    $draft = Article::factory()->create(['status' => 'draft', 'is_published' => false]);
    $other = Article::factory()->create(['status' => 'draft', 'is_published' => false]);

    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'publish-articles', 'ids' => [(string) $draft->id, (string) $other->id]])
        ->assertHasNoErrors()->assertSee(['Опубликовано: 2', '"processed":2']);

    expect($draft->refresh()->is_published)->toBeTrue();
});

it('validates the mini form of an action', function (): void {
    $article = Article::factory()->create();

    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'schedule-publication', 'ids' => [(string) $article->id], 'data' => ['published_on' => 'not a date']])
        ->assertHasErrors(['Проверка не пройдена']);
});

it('names the available actions when the key is wrong and refuses a bad selection', function (): void {
    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'nope', 'ids' => ['1']])->assertHasErrors(['Действия «nope» нет. Доступные:']);
    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'publish-articles', 'ids' => []])->assertHasErrors(['Укажите ids']);
    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'publish-articles', 'ids' => ['1; drop']])->assertHasErrors(['Укажите ids']);
    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'publish-articles', 'ids' => ['999']])->assertHasErrors(['Нет записей']);
});

it('blocks the built-in deletes while deletion is disabled and follows the Policy once enabled', function (): void {
    $draft = Article::factory()->create(['is_published' => false]);
    $published = Article::factory()->create(['is_published' => true]);

    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'delete', 'ids' => [(string) $draft->id]])->assertHasErrors(['Удаление отключено']);
    expect(Article::query()->count())->toBe(2);

    config(['fastleo.mcp.allow_delete' => true]);

    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'delete', 'ids' => [(string) $draft->id, (string) $published->id]])
        ->assertHasNoErrors()->assertSee(['"processed":1', '"skipped":1']);

    expect(Article::query()->pluck('id')->all())->toBe([$published->id]);
});

it('does not run actions meant for the trash', function (): void {
    $article = Article::factory()->create();
    $article->delete();

    mcp()->tool(RunAction::class, ['resource' => 'articles', 'action' => 'restore', 'ids' => [(string) $article->id]])->assertHasErrors(['корзин']);
});

it('refuses every tool when no admin is signed in', function (): void {
    $plain = User::factory()->create();

    mcp($plain)->tool(ListResources::class)->assertHasErrors(['без администратора']);
    mcp($plain)->tool(CreateRecord::class, ['resource' => 'articles', 'values' => ['title' => 'x', 'status' => 'draft']])->assertHasErrors(['без администратора']);

    expect(Article::query()->count())->toBe(0);
});

it('writes changes made through MCP to the activity log under the admin', function (): void {
    $admin = admin(['name' => 'Мария']);
    resolve(ActivityRecorder::class)->start();

    mcp($admin)->tool(CreateRecord::class, ['resource' => 'articles', 'values' => ['title' => 'Logged', 'status' => 'draft']])->assertHasNoErrors();

    $entry = Activity::query()->latest('id')->firstOrFail();

    expect($entry->event)->toBe('created')
        ->and((int) $entry->user_id)->toBe($admin->id);

    resolve(ActivityRecorder::class)->stop();
});

it('gives out only the block fields of nested records, not their service columns', function (): void {
    $article = Article::factory()->create();
    $article->links()->create(['title' => 'Docs', 'url' => 'https://example.com']);
    $article->banner()->create(['title' => 'Promo']);

    mcp()->tool(GetRecord::class, ['resource' => 'articles', 'id' => (string) $article->id])
        ->assertHasNoErrors()
        ->assertSee(['"links":[{"id":', '"title":"Docs"', '"banner":{"id":', '"title":"Promo"'])
        ->assertDontSee(['created_at', 'updated_at', 'article_id']);
});

it('keeps the other languages when one translation is updated', function (): void {
    $page = Page::factory()->create(['title' => ['ru' => 'Заголовок', 'en' => 'Title']]);

    mcp()->tool(UpdateRecord::class, ['resource' => 'site-pages', 'id' => (string) $page->id, 'values' => ['title' => ['ru' => 'Новый']]])
        ->assertHasNoErrors();

    expect($page->refresh()->title)->toBeStoredJson(['ru' => 'Новый', 'en' => 'Title']);
});

it('refuses to drop nested records by an incomplete list while deletion is off', function (): void {
    $article = Article::factory()->create();
    $first = $article->links()->create(['title' => 'First', 'url' => 'https://a.example']);
    $second = $article->links()->create(['title' => 'Second', 'url' => 'https://b.example']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'articles', 'id' => (string) $article->id, 'values' => ['links' => [['title' => 'Brand new', 'url' => 'https://c.example']]]])
        ->assertHasErrors(['удаление отключено']);

    expect($article->links()->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('refuses to replace a one-to-one record that was sent without its id', function (): void {
    $article = Article::factory()->create();
    $banner = $article->banner()->create(['title' => 'Old']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'articles', 'id' => (string) $article->id, 'values' => ['banner' => ['title' => 'Replacement']]])
        ->assertHasErrors(['удаление отключено']);

    expect($article->banner()->sole()->id)->toBe($banner->id);
});

it('updates nested records when the whole list with their ids is sent', function (): void {
    $article = Article::factory()->create();
    $first = $article->links()->create(['title' => 'First', 'url' => 'https://a.example']);
    $second = $article->links()->create(['title' => 'Second', 'url' => 'https://b.example']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'articles', 'id' => (string) $article->id, 'values' => ['links' => [
        ['id' => $first->id, 'title' => 'First edited', 'url' => 'https://a.example'],
        ['id' => $second->id, 'title' => 'Second', 'url' => 'https://b.example'],
        ['title' => 'Third', 'url' => 'https://c.example'],
    ]]])->assertHasNoErrors();

    expect($article->links()->pluck('title')->all())->toBe(['First edited', 'Second', 'Third']);
});

it('removes nested records missing from the list only with --allow-delete', function (): void {
    config(['fastleo.mcp.allow_delete' => true]);
    $article = Article::factory()->create();
    $first = $article->links()->create(['title' => 'First', 'url' => 'https://a.example']);
    $article->links()->create(['title' => 'Second', 'url' => 'https://b.example']);

    mcp()->tool(UpdateRecord::class, ['resource' => 'articles', 'id' => (string) $article->id, 'values' => ['links' => [['id' => $first->id, 'title' => 'First', 'url' => 'https://a.example']]]])
        ->assertHasNoErrors();

    expect($article->links()->pluck('title')->all())->toBe(['First']);
});

describe('sections with personal data', function (): void {
    beforeEach(fn () => useFixtureResources('McpPersonal'));

    it('closes sections with e-mail or phone fields by field type, by name, in repeaters and in blocks', function (): void {
        $sections = mcp()->tool(ListResources::class)->assertHasNoErrors();

        $sections->assertSee(['"resource":"plain"', '"resource":"open-phone"'])
            ->assertDontSee(['"resource":"phone-field"', '"resource":"email-column"', '"resource":"nested-phone"', '"resource":"block-phone"', '"resource":"closed"']);
    });

    it('does not open a closed section to any tool', function (string $slug): void {
        mcp()->tool(DescribeResource::class, ['resource' => $slug])->assertHasErrors(['Раздела «'.$slug.'» нет']);
        mcp()->tool(ListRecords::class, ['resource' => $slug])->assertHasErrors(['Раздела «'.$slug.'» нет']);
        mcp()->tool(CreateRecord::class, ['resource' => $slug, 'values' => ['title' => 'x']])->assertHasErrors(['Раздела «'.$slug.'» нет']);
    })->with(['phone-field', 'email-column', 'nested-phone', 'block-phone', 'closed']);

    it('lets the site open such a section with $mcpPersonalData = true', function (): void {
        mcp()->tool(DescribeResource::class, ['resource' => 'open-phone'])->assertHasNoErrors();
    });

    it('closes the admins section by default', function (): void {
        config(['fastleo.users.resource' => UserResource::class]);
        Fastleo::resources()->flush();

        mcp()->tool(ListResources::class)->assertSee('"resource":"plain"')->assertDontSee('"resource":"users"');
    });
});
