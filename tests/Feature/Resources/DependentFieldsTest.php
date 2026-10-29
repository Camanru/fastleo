<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Js;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Topic;

beforeEach(function (): void {
    useFixtureResources('Dependent');
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Форма раздела «promo-articles».
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoData(array $overrides = []): array
{
    return ['title' => 'Статья', 'type' => 'news', 'is_featured' => '0', 'layout' => 'wide', 'color' => 'loud', ...$overrides];
}

describe('visible when', function (): void {
    it('wraps the field with its condition', function (): void {
        $this->get(route('fastleo.resources.create', 'promo-articles'))
            ->assertOk()
            ->assertSee('x-data="fastleoVisible('.Js::from(['field' => 'is_featured', 'values' => ['1']])->toHtml().')"', false)
            ->assertSee('x-data="fastleoVisible('.Js::from(['field' => 'type', 'values' => ['promo']])->toHtml().')"', false);
    });

    it('neither checks nor saves a hidden field', function (): void {
        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['starts_at' => '2026-03-08T10:00', 'phone' => '']))
            ->assertSessionHasNoErrors();

        $article = Article::query()->sole();
        expect($article->starts_at)->toBeNull()->and($article->phone)->toBeNull();
    });

    it('checks and saves a shown field', function (): void {
        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['is_featured' => '1', 'type' => 'promo', 'layout' => 'banner']))
            ->assertSessionHasErrors(['starts_at', 'phone']);

        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['is_featured' => '1', 'starts_at' => '2026-03-08T10:00', 'type' => 'promo', 'phone' => '+7 900', 'layout' => 'banner']))
            ->assertSessionHasNoErrors();

        expect(Article::query()->sole()->phone)->toBe('+7 900');
    });

    it('applies the condition inside each nested block', function (): void {
        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['links' => [
            ['title' => 'Другое', 'url' => 'не адрес'],
            ['title' => 'Сайт', 'url' => ''],
        ]]))->assertSessionHasErrors('links.1.url')->assertSessionDoesntHaveErrors('links.0.url');

        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['links' => [
            ['title' => 'Другое', 'url' => 'https://example.com'],
            ['title' => 'Сайт', 'url' => 'https://site.ru'],
        ]]))->assertSessionHasNoErrors();

        expect(Article::query()->sole()->links()->orderBy('id')->pluck('url')->all())->toBe([null, 'https://site.ru']);
    });

    it('hides the field on the record page when the condition is false', function (): void {
        $article = Article::factory()->create(['phone' => '+7 900', 'type' => 'news', 'status' => ArticleStatus::Draft]);

        $this->get(route('fastleo.resources.show', ['promo-articles', $article->id]))->assertOk()->assertDontSee('Телефон акции');

        $article->update(['type' => 'promo']);
        $this->get(route('fastleo.resources.show', ['promo-articles', $article->id]))->assertSee('Телефон акции');
    });
});

describe('depends on', function (): void {
    it('offers the subcategories of the chosen category', function (): void {
        $catalog = Category::factory()->create(['title' => 'Каталог']);
        $fans = Category::factory()->create(['title' => 'Вентиляторы', 'parent_id' => $catalog->id]);
        Category::factory()->create(['title' => 'Другое', 'parent_id' => Category::factory()->create()->id]);
        $article = Article::factory()->create(['category_id' => $catalog->id, 'subcategory_id' => $fans->id]);

        $this->getJson(route('fastleo.resources.options', ['promo-articles', 'subcategory_id']).'?depends='.$catalog->id)
            ->assertOk()
            ->assertExactJson(['options' => [['value' => (string) $fans->id, 'label' => 'Вентиляторы']]]);

        $this->getJson(route('fastleo.resources.options', ['promo-articles', 'subcategory_id']))->assertExactJson(['options' => []]);

        $this->get(route('fastleo.resources.edit', ['promo-articles', $article->id]))
            ->assertOk()
            ->assertSee(Js::from([
                'multiple' => false,
                'options' => [['value' => (string) $fans->id, 'label' => 'Вентиляторы']],
                'selected' => [['value' => (string) $fans->id, 'label' => 'Вентиляторы']],
                'url' => route('fastleo.resources.options', ['promo-articles', 'subcategory_id']),
                'remote' => false,
                'depends' => 'category_id',
                'record' => (string) $article->id,
            ])->toHtml(), false);
    });

    it('accepts only a subcategory of the category sent in the same form', function (): void {
        $catalog = Category::factory()->create();
        $fans = Category::factory()->create(['parent_id' => $catalog->id]);
        $foreign = Category::factory()->create(['parent_id' => Category::factory()->create()->id]);

        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['category_id' => (string) $catalog->id, 'subcategory_id' => (string) $foreign->id]))
            ->assertSessionHasErrors(['subcategory_id' => 'Выбрано недопустимое значение поля «Подраздел».']);

        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['category_id' => (string) $catalog->id, 'subcategory_id' => (string) $fans->id]))
            ->assertSessionHasNoErrors();

        expect(Article::query()->sole()->subcategory_id)->toBe($fans->id);
    });

    it('builds select options from the value of another field', function (): void {
        $this->getJson(route('fastleo.resources.options', ['promo-articles', 'layout']).'?depends=promo')
            ->assertExactJson(['options' => [['value' => 'banner', 'label' => 'С баннером']]]);

        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['layout' => 'banner']))
            ->assertSessionHasErrors(['layout' => 'Выбрано недопустимое значение поля «Вид страницы».']);

        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['type' => 'promo', 'phone' => '1', 'layout' => 'banner']))
            ->assertSessionHasNoErrors();

        $article = Article::query()->sole();
        $this->get(route('fastleo.resources.index', 'promo-articles'))->assertSee('С баннером');
        $this->get(route('fastleo.resources.edit', ['promo-articles', $article->id]))
            ->assertSee('fastleoDependentSelect', false)
            ->assertSee('<option value="banner" selected>С баннером</option>', false);
    });

    it('takes a locked dependency from the saved record, not from the request', function (): void {
        $article = Article::factory()->create(['status' => ArticleStatus::Published]);

        $this->get(route('fastleo.resources.edit', ['promo-articles', $article->id]))
            ->assertOk()
            ->assertSee('<option value="calm"', false)
            ->assertDontSee('<option value="loud"', false);

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['status' => 'draft', 'color' => 'loud']))
            ->assertSessionHasErrors('color');

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['status' => 'draft', 'color' => 'calm']))
            ->assertSessionHasNoErrors();

        expect($article->fresh()?->status)->toBe(ArticleStatus::Published);
        $this->get(route('fastleo.resources.show', ['promo-articles', $article->id]))->assertOk();
    });

    it('builds the create form options from the default of the controlling field', function (): void {
        $this->get(route('fastleo.resources.create', 'promo-articles'))
            ->assertOk()
            ->assertSee('<option value="banner"', false)
            ->assertDontSee('<option value="wide"', false);
    });

    it('ignores a forged locked dependency in the form after an error and in the options', function (): void {
        $article = Article::factory()->create(['status' => ArticleStatus::Published]);
        $edit = route('fastleo.resources.edit', ['promo-articles', $article->id]);
        $options = route('fastleo.resources.options', ['promo-articles', 'color']);

        $this->from($edit)->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['title' => '', 'status' => 'draft']))
            ->assertSessionHasErrors('title');
        $this->get($edit)->assertSee('<option value="calm"', false)->assertDontSee('<option value="loud"', false);

        $this->getJson($options.'?depends=published')->assertExactJson(['options' => [['value' => 'loud', 'label' => 'Громкий']]]);
        $this->getJson($options.'?depends=draft&record='.$article->id)->assertExactJson(['options' => [['value' => 'calm', 'label' => 'Спокойный']]]);
        $this->getJson($options.'?record=abc')->assertNotFound();
    });

    it('keeps a saved link that no longer fits the dependency until the dependency changes', function (): void {
        $topic = Topic::factory()->create(['title' => 'Летняя', 'sort' => 1]);
        $article = Article::factory()->create(['type' => 'promo']);
        $article->topics()->attach($topic);
        $topic->forceFill(['sort' => 2])->save();

        $this->get(route('fastleo.resources.edit', ['promo-articles', $article->id]))->assertOk()->assertSee('Летняя');

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['type' => 'promo', 'phone' => '+7 900 000-00-00', 'layout' => 'banner', 'topics' => [(string) $topic->id]]))
            ->assertSessionHasNoErrors();
        expect($article->topics()->pluck('topics.id')->all())->toBe([$topic->id]);

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['topics' => '']))
            ->assertSessionHasNoErrors();
        expect($article->topics()->count())->toBe(0);
    });

    it('rejects a saved link when the dependency changed and the link no longer belongs to it', function (): void {
        $catalog = Category::factory()->create();
        $fans = Category::factory()->create(['parent_id' => $catalog->id]);
        $other = Category::factory()->create();
        $article = Article::factory()->create(['category_id' => $catalog->id, 'subcategory_id' => $fans->id]);

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['category_id' => (string) $catalog->id, 'subcategory_id' => (string) $fans->id]))
            ->assertSessionHasNoErrors();

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['category_id' => (string) $other->id, 'subcategory_id' => (string) $fans->id]))
            ->assertSessionHasErrors('subcategory_id');
    });

    it('keeps a saved value of a dependent list that is outside the options until the dependency changes', function (): void {
        $article = Article::factory()->create(['type' => 'promo', 'layout' => 'wide', 'phone' => '1']);

        $this->get(route('fastleo.resources.edit', ['promo-articles', $article->id]))
            ->assertSee('<option value="wide" selected>wide</option>', false);

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['type' => 'promo', 'phone' => '1', 'layout' => 'wide']))
            ->assertSessionHasNoErrors();

        $this->put(route('fastleo.resources.update', ['promo-articles', $article->id]), promoData(['type' => 'news', 'layout' => 'wide']))
            ->assertSessionHasNoErrors();
        expect($article->fresh()?->layout)->toBe('wide');
    });

    it('does not break on a dependency sent as an array', function (): void {
        $this->post(route('fastleo.resources.store', 'promo-articles'), promoData(['type' => ['promo'], 'layout' => 'banner']))
            ->assertSessionHasErrors();
    });
});
