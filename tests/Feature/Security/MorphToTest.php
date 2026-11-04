<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Tests\Fixtures\Morph\RemarkResource;
use Fastleo\Tests\Fixtures\Policies\NoListProductPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Article;
use Workbench\App\Models\Product;
use Workbench\App\Models\Remark;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

afterEach(function (): void {
    RemarkResource::$variant = 'default';
});

/**
 * Адрес поиска записей поля «О чём» с выбранным разделом.
 */
function morphSearch(?string $type, string $q = ''): string
{
    return route('fastleo.resources.options', ['remarks', 'subject', 'depends' => $type, 'q' => $q]);
}

/**
 * Сохранить заметку с объектом и вернуть ответ.
 *
 * @param  array<string, mixed>  $data
 */
function postRemark(array $data): TestResponse
{
    return test()->post(route('fastleo.resources.store', 'remarks'), ['body' => 'Заметка', ...$data]);
}

describe('search of the records of a section', function (): void {
    it('does not answer a guest', function (): void {
        auth(Fastleo::GUARD)->logout();

        $this->getJson(morphSearch('articles'))->assertUnauthorized();
    });

    it('finds the records of the chosen section by title', function (): void {
        Article::factory()->create(['title' => 'Приточная вентиляция']);
        Article::factory()->create(['title' => 'Кабель']);

        $labels = collect($this->getJson(morphSearch('articles', 'вентиляц'))->assertOk()->json('options'))->pluck('label');

        expect($labels->all())->toBe(['Приточная вентиляция']);
    });

    it('offers only the products that the narrowing of the type lets through', function (): void {
        Product::factory()->create(['title' => 'Виден', 'is_visible' => true]);
        Product::factory()->create(['title' => 'Скрыт', 'is_visible' => false]);

        $labels = collect($this->getJson(morphSearch('products'))->json('options'))->pluck('label');

        expect($labels->all())->toBe(['Виден']);
    });

    it('gives nothing without a section', function (?string $type): void {
        Article::factory()->create();

        $this->getJson(morphSearch($type))->assertOk()->assertExactJson(['options' => []]);
    })->with(['no parameter' => [null], 'empty' => ['']]);

    it('refuses a section that is not in the types, a class name and a section closed to the admin', function (string $type): void {
        Gate::policy(Product::class, NoListProductPolicy::class);

        $this->getJson(morphSearch($type))->assertNotFound();
    })->with([
        'a section of the site outside the types' => 'remarks',
        'the class of a model' => Article::class,
        'a closed section' => 'products',
        'nonsense' => '../../etc',
    ]);

    it('does not answer for a field the admin cannot change', function (): void {
        useFixtureResources('Morph');
        RemarkResource::$variant = 'readonly';

        $this->getJson(morphSearch('articles'))->assertNotFound();
    });

    it('escapes the search pattern', function (): void {
        Article::factory()->create(['title' => '100% скидка']);
        Article::factory()->create(['title' => 'Скидка']);

        expect(collect($this->getJson(morphSearch('articles', '100%'))->json('options'))->pluck('label')->all())->toBe(['100% скидка']);
    });
});

describe('what the form can send', function (): void {
    it('refuses everything but a pair of a section and a record of its variants', function (mixed $subject): void {
        Article::factory()->create();

        postRemark(['subject' => $subject])->assertSessionHasErrors('subject');

        expect(Remark::query()->count())->toBe(0);
    })->with([
        'the class of a model' => [['type' => Article::class, 'id' => '1']],
        'a section outside the types' => [['type' => 'remarks', 'id' => '1']],
        'a list instead of the section' => [['type' => ['articles'], 'id' => '1']],
        'a list instead of the record' => [['type' => 'articles', 'id' => ['1']]],
        'an extra key' => [['type' => 'articles', 'id' => '1', 'extra' => 'x']],
        'a text instead of a pair' => ['articles:1'],
        'a fraction' => [['type' => 'articles', 'id' => '1.0']],
        'a sign' => [['type' => 'articles', 'id' => '+1']],
        'a condition' => [['type' => 'articles', 'id' => '1 OR 1=1']],
        'text for a numeric key' => [['type' => 'articles', 'id' => 'abc']],
    ]);

    it('does not write the columns that were sent by hand', function (): void {
        $article = Article::factory()->create();

        postRemark(['subject' => ['type' => 'articles', 'id' => (string) $article->id], 'subject_type' => 'App\\Models\\User', 'subject_id' => '77'])->assertSessionHasNoErrors();

        $remark = Remark::query()->sole();

        expect([$remark->subject_type, $remark->subject_id])->toBe([Article::class, $article->id]);
    });

    it('does not accept the stored key under another section', function (): void {
        $hidden = Product::factory()->create(['is_visible' => false]);
        $remark = Remark::query()->forceCreate(['body' => 'Старая', 'subject_type' => Product::class, 'subject_id' => $hidden->id]);

        $this->put(route('fastleo.resources.update', ['remarks', $remark->id]), ['body' => 'Старая', 'subject' => ['type' => 'articles', 'id' => (string) $hidden->id]])
            ->assertSessionHasErrors('subject');

        expect([$remark->fresh()?->subject_type, $remark->fresh()?->subject_id])->toBe([Product::class, $hidden->id]);
    });

    it('refuses a record of a section that is closed to the admin', function (): void {
        $product = Product::factory()->create(['is_visible' => true]);
        Gate::policy(Product::class, NoListProductPolicy::class);

        postRemark(['subject' => ['type' => 'products', 'id' => (string) $product->id]])->assertSessionHasErrors('subject');
    });

    it('refuses a record in the trash', function (): void {
        $deleted = Article::factory()->create();
        $deleted->delete();

        postRemark(['subject' => ['type' => 'articles', 'id' => (string) $deleted->id]])->assertSessionHasErrors('subject');
    });
});
