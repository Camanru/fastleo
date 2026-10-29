<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Js;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

/**
 * Минимальная форма статьи для проверки специальных полей.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function specialPayload(array $overrides = []): array
{
    return ['type' => 'news', 'title' => 'О компании', 'status' => 'draft', 'views' => '0', ...$overrides];
}

it('renders the special fields', function (): void {
    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertOk()
        ->assertSee('x-data="fastleoSlug" data-source="title"', false)
        ->assertSee('type="radio"', false)
        ->assertSee('type="range"', false)
        ->assertSee('max="10"', false)
        ->assertSee('fastleoTags', false)
        ->assertSee('<option value="новинка">', false)
        ->assertSee('x-data="fastleoCode"', false)
        ->assertSee('x-data="fastleoMarkdown" data-url="'.route('fastleo.markdown').'"', false);
});

it('saves the special fields as entered', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), specialPayload([
        'slug' => 'about',
        'layout' => 'narrow',
        'rating' => '7',
        'keywords' => '',
        'body' => "# Заголовок\n\nТекст  ",
        'head_code' => "<script>\n    counter();\n</script>",
    ]))->assertRedirect();

    $article = Article::query()->sole();

    expect($article->slug)->toBe('about')
        ->and($article->layout)->toBe('narrow')
        ->and($article->rating)->toBe(7)
        ->and($article->keywords)->toBe([])
        ->and($article->body)->toBe("# Заголовок\n\nТекст")
        ->and($article->head_code)->toBe("<script>\n    counter();\n</script>");
});

describe('slug', function (): void {
    it('builds an empty slug from the title and adds a suffix when it is taken', function (): void {
        Article::factory()->create(['slug' => 'o-kompanii']);
        Article::factory()->create(['slug' => 'o-kompanii-2'])->delete();

        $this->post(route('fastleo.resources.store', 'articles'), specialPayload(['slug' => '']))->assertRedirect();

        expect(Article::query()->latest('id')->value('slug'))->toBe('o-kompanii-3');
    });

    it('treats the address built from the title by the form script as automatic and adds a suffix instead of an error', function (): void {
        Article::factory()->create(['slug' => 'o-kompanii']);

        $this->post(route('fastleo.resources.store', 'articles'), specialPayload(['slug' => 'o-kompanii']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect(Article::query()->latest('id')->value('slug'))->toBe('o-kompanii-2');
    });

    it('keeps a free automatic address as it is', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), specialPayload(['slug' => 'o-kompanii']))->assertRedirect();

        expect(Article::query()->sole()->slug)->toBe('o-kompanii');
    });

    it('does not change the address of a record when its title still matches the saved address', function (): void {
        $article = Article::factory()->create(['title' => 'О компании', 'slug' => 'o-kompanii']);
        Article::factory()->create(['slug' => 'o-kompanii-2']);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), specialPayload(['slug' => 'o-kompanii']))->assertSessionHasNoErrors();

        expect($article->fresh()?->slug)->toBe('o-kompanii');
    });

    it('rejects a taken or malformed slug typed by hand', function (string $slug, string $message): void {
        Article::factory()->create(['slug' => 'about']);

        $this->post(route('fastleo.resources.store', 'articles'), specialPayload(['slug' => $slug]))
            ->assertSessionHasErrors(['slug' => $message]);
    })->with([
        'taken' => ['about', 'Такое значение поля «Адрес» уже есть.'],
        'capital letters' => ['About', 'Поле «Адрес» заполнено в неверном формате.'],
        'spaces' => ['o kompanii', 'Поле «Адрес» заполнено в неверном формате.'],
    ]);

    it('keeps its own slug when the record is saved again', function (): void {
        $article = Article::factory()->create(['slug' => 'about']);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), specialPayload(['slug' => 'about']))
            ->assertSessionHasNoErrors();
    });
});

describe('tags', function (): void {
    it('stores trimmed unique tags and shows them on the form', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), specialPayload(['keywords' => ['', ' акция ', 'акция', 'обзор']]))
            ->assertRedirect();

        $article = Article::query()->sole();

        expect($article->keywords)->toBe(['акция', 'обзор']);
        $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
            ->assertSee('tags: '.Js::from(['акция', 'обзор']), false);
    });

    it('clears the tags by the hidden empty value', function (): void {
        $article = Article::factory()->create(['keywords' => ['акция']]);

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), specialPayload(['keywords' => '']));

        expect($article->fresh()?->keywords)->toBe([]);
    });

    it('rejects a too long tag', function (): void {
        $this->post(route('fastleo.resources.store', 'articles'), specialPayload(['keywords' => [str_repeat('а', 51)]]))
            ->assertSessionHasErrors('keywords.0');
    });
});

it('rejects values outside radio options and range bounds', function (string $field, string $value): void {
    $this->post(route('fastleo.resources.store', 'articles'), specialPayload([$field => $value]))
        ->assertSessionHasErrors($field);
})->with([
    'radio' => ['layout', 'full'],
    'range above max' => ['rating', '11'],
    'enum' => ['status', 'deleted'],
]);

it('shows markdown as safe html and code as text on the record page', function (): void {
    $article = Article::factory()->create([
        'body' => '**Жирный** <script>alert(1)</script> [ссылка](javascript:alert(1))',
        'head_code' => '<b>код</b>',
    ]);

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('<strong>Жирный</strong>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('href="javascript:', false)
        ->assertSee('<pre class="overflow-x-auto font-mono text-xs whitespace-pre">&lt;b&gt;код&lt;/b&gt;</pre>', false);
});

describe('password', function (): void {
    beforeEach(function (): void {
        useFixtureResources('Users');
    });

    it('requires a confirmed password on create and stores its hash', function (): void {
        $this->post(route('fastleo.resources.store', 'users'), ['name' => 'Анна', 'email' => 'anna@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'other'])
            ->assertSessionHasErrors(['password' => 'Поле «Пароль» не совпадает с подтверждением.']);

        $this->post(route('fastleo.resources.store', 'users'), ['name' => 'Анна', 'email' => 'anna@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['password' => 'Длина поля «Пароль» должна быть не меньше 8 символов.']);

        $this->post(route('fastleo.resources.store', 'users'), ['name' => 'Анна', 'email' => 'anna@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'anna@example.com')->sole();

        expect(Hash::check('secret-pass', $user->password))->toBeTrue();
    });

    it('keeps the old password when the field is left empty and never shows the hash', function (): void {
        $user = User::factory()->create(['password' => 'old-password']);
        $hash = $user->password;

        $this->get(route('fastleo.resources.edit', ['users', $user->id]))
            ->assertOk()
            ->assertSee('Оставьте пустым, чтобы не менять')
            ->assertDontSee($hash);

        $this->put(route('fastleo.resources.update', ['users', $user->id]), ['name' => 'Новое имя', 'email' => $user->email, 'password' => '', 'password_confirmation' => ''])
            ->assertSessionHasNoErrors();

        expect($user->fresh()?->password)->toBe($hash)
            ->and($user->fresh()?->name)->toBe('Новое имя');

        $this->get(route('fastleo.resources.index', 'users'))->assertDontSee($hash);
        $this->get(route('fastleo.resources.show', ['users', $user->id]))->assertDontSee('Пароль');
    });
});
