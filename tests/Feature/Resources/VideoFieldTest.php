<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Video;
use Fastleo\Support\VideoLinkRule;
use Illuminate\Support\Facades\Blade;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('saves a link to a video as it was pasted', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData(['video' => 'https://youtu.be/dQw4w9WgXcQ']))->assertSessionHasNoErrors();

    expect(Article::query()->firstOrFail()->video)->toBe('https://youtu.be/dQw4w9WgXcQ');
});

it('refuses links that are not a video of a known service', function (string $link): void {
    $this->from(route('fastleo.resources.create', 'articles'))
        ->post(route('fastleo.resources.store', 'articles'), articleData(['video' => $link]))
        ->assertSessionHasErrors(['video' => 'Укажите ссылку на видео YouTube, Rutube или VK Видео.']);

    expect(Article::query()->count())->toBe(0);
})->with(['other site' => 'https://example.com/video', 'lookalike host' => 'https://youtube.com.evil.ru/watch?v=dQw4w9WgXcQ', 'javascript' => 'javascript:alert(1)']);

it('shows the player under the input and on the page of the record', function (): void {
    $article = Article::factory()->create(['video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

    $this->get(route('fastleo.resources.edit', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('type="url"', false)
        ->assertSee('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', false);

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', false);
});

it('shows only the link in the list and when the site formats the value itself', function (): void {
    $article = Article::factory()->create(['video' => 'https://youtu.be/dQw4w9WgXcQ']);
    $field = Video::make('video');

    expect((string) $field->listDisplay($article))->toContain('href="https://youtu.be/dQw4w9WgXcQ"')->not->toContain('<iframe')
        ->and((string) $field->formatUsing(fn (mixed $value): string => 'видео')->display($article))->toBe('видео');
});

it('shows the link of a value that is not a video as it is', function (): void {
    $article = Article::factory()->create(['video' => 'https://example.com/old']);

    expect((string) Video::make('video')->display($article))->toContain('href="https://example.com/old"')->not->toContain('<iframe');
});

it('renders the player on the site and skips what is not a video', function (): void {
    $html = Blade::render('<x-fastleo::video :url="$url" title="Приём" class="my" />', ['url' => 'https://rutube.ru/video/0123456789abcdef0123456789abcdef/']);

    expect($html)->toContain('src="https://rutube.ru/play/embed/0123456789abcdef0123456789abcdef"')
        ->toContain('title="Приём"')
        ->toContain('loading="lazy"')
        ->toContain('class="my"')
        ->and(trim(Blade::render('<x-fastleo::video :url="$url" />', ['url' => 'https://example.com/x'])))->toBe('')
        ->and(trim(Blade::render('<x-fastleo::video />')))->toBe('');
});

it('says why the link is refused in Russian even when the site works in another locale', function (): void {
    app()->setLocale('en');

    $errors = validator(['video' => 'https://example.com/clip'], ['video' => [new VideoLinkRule]])->errors();

    expect($errors->first('video'))->toBe('Укажите ссылку на видео YouTube, Rutube или VK Видео.');
});
