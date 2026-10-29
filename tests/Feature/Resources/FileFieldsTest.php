<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    Storage::fake('public');
    $this->actingAs(admin(), Fastleo::GUARD);
});

it('renders image, gallery and file fields with the upload folder and the picker', function (): void {
    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertOk()
        ->assertSee('fastleoFile(', false)
        ->assertSee('folder=uploads%2Farticles', false)
        ->assertSee('picker=field', false)
        ->assertSee('accept=images', false)
        ->assertSee('fastleoGallery(', false)
        ->assertSee('multiple=1', false)
        ->assertSee('folder=uploads%2Fdocuments', false)
        ->assertSee('id="fastleo-picker"', false);
});

it('saves paths of an image, a gallery in the given order and a file', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData([
        'cover' => 'uploads/articles/cover.jpg',
        'gallery' => ['uploads/articles/2.jpg', 'uploads/articles/1.png', 'uploads/articles/2.jpg', ''],
        'attachment' => 'uploads/documents/price.pdf',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $article = Article::query()->sole();

    expect($article->cover)->toBe('uploads/articles/cover.jpg')
        ->and($article->gallery)->toBe(['uploads/articles/2.jpg', 'uploads/articles/1.png'])
        ->and($article->attachment)->toBe('uploads/documents/price.pdf');
});

it('clears an image and a gallery', function (): void {
    $article = Article::factory()->create(['cover' => 'uploads/a.jpg', 'gallery' => ['uploads/b.jpg']]);

    $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData(['cover' => '', 'gallery' => '']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($article->refresh()->cover)->toBeNull()->and($article->gallery)->toBe([]);
});

it('refuses a path outside the uploads root', function (string $field, mixed $value, string $key, string $label): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData([$field => $value]))
        ->assertSessionHasErrors([$key => "Поле «{$label}»: выберите файл в менеджере файлов."]);

    expect(Article::query()->count())->toBe(0);
})->with([
    ['cover', '../.env', 'cover', 'Обложка'],
    ['cover', 'uploads/../.env', 'cover', 'Обложка'],
    ['cover', 'secret.jpg', 'cover', 'Обложка'],
    ['cover', 'fastleo/thumbs/uploads/a.jpg.webp', 'cover', 'Обложка'],
    ['cover', 'uploads', 'cover', 'Обложка'],
    ['attachment', 'uploads/.env', 'attachment', 'Файл для скачивания'],
    ['gallery', ['../../etc/passwd.jpg'], 'gallery.0', 'Фотогалерея'],
]);

it('refuses a file of a type the field does not take', function (string $field, mixed $value, string $key, string $message): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData([$field => $value]))
        ->assertSessionHasErrors([$key => $message]);
})->with([
    ['cover', 'uploads/price.pdf', 'cover', 'Поле «Обложка»: подходят только файлы png, jpg, jpeg, gif, webp.'],
    ['attachment', 'uploads/archive.zip', 'attachment', 'Поле «Файл для скачивания»: подходят только файлы pdf, docx, xlsx.'],
    ['gallery', ['uploads/a.jpg', 'uploads/b.pdf'], 'gallery.1', 'Поле «Фотогалерея»: подходят только файлы png, jpg, jpeg, gif, webp.'],
]);

it('limits the number of pictures in a gallery', function (): void {
    $paths = array_map(fn (int $i): string => "uploads/{$i}.jpg", range(1, 21));

    $this->post(route('fastleo.resources.store', 'articles'), articleData(['gallery' => $paths]))
        ->assertSessionHasErrors('gallery');
});

it('saves an image inside a nested block', function (): void {
    $this->post(route('fastleo.resources.store', 'articles'), articleData([
        'banner' => ['title' => 'Акция', 'image' => 'uploads/banners/sale.jpg'],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(Article::query()->sole()->banner?->image)->toBe('uploads/banners/sale.jpg');

    $this->post(route('fastleo.resources.store', 'articles'), articleData([
        'title' => 'Вторая',
        'banner' => ['title' => 'Акция', 'image' => '../.env'],
    ]))->assertSessionHasErrors('banner.image');
});

it('shows thumbnails and links in the list and on the record page', function (): void {
    $article = Article::factory()->create([
        'cover' => 'uploads/articles/cover.jpg',
        'gallery' => ['uploads/articles/1.jpg'],
        'attachment' => 'uploads/documents/price.pdf',
    ]);

    $this->get(route('fastleo.resources.index', 'articles'))
        ->assertOk()
        ->assertSee(e(route('fastleo.files.thumb', ['path' => 'uploads/articles/cover.jpg'])), false);

    $this->get(route('fastleo.resources.show', ['articles', $article->id]))
        ->assertOk()
        ->assertSee(e(route('fastleo.files.thumb', ['path' => 'uploads/articles/1.jpg'])), false)
        ->assertSee(Storage::disk('public')->url('uploads/documents/price.pdf'), false)
        ->assertSee('price.pdf');
});

it('keeps chosen files in the form after a validation error', function (): void {
    $this->from(route('fastleo.resources.create', 'articles'))
        ->post(route('fastleo.resources.store', 'articles'), articleData([
            'title' => '',
            'cover' => 'uploads/articles/cover.jpg',
            'gallery' => ['uploads/articles/1.jpg'],
        ]));

    $this->get(route('fastleo.resources.create', 'articles'))
        ->assertSee('cover.jpg', false)
        ->assertSee('value="uploads/articles/1.jpg"', false);
});
