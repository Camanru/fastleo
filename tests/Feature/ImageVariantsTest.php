<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Fields\Image;
use Fastleo\Files\GenerateImageVariants;
use Fastleo\Files\Variants;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\ViewException;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    $this->disk = Storage::fake('public');
    config(['fastleo.images.presets' => [
        'card' => ['widths' => [400, 800, 2000], 'ratio' => '4:3'],
        'wide' => ['widths' => [300], 'ratio' => null, 'watermark' => true],
        'small' => ['widths' => [200, 300]],
        'photo' => ['widths' => [480]],
    ], 'fastleo.images.watermark.path' => null]);
});

/**
 * Положить на диск картинку нужного размера.
 */
function putImage(string $path, int $width, int $height): void
{
    test()->disk->put($path, UploadedFile::fake()->image('a.jpg', $width, $height)->getContent());
}

it('makes webp variants in the ratio of the preset, no wider than the picture', function (): void {
    putImage('uploads/photo.jpg', 1600, 900);

    expect(Variants::generate('uploads/photo.jpg', 'card'))->toBe([400 => 300, 800 => 600, 1200 => 900]);

    $variant = (string) $this->disk->get('fastleo/variants/card/uploads/photo.jpg/800.webp');
    expect(getimagesizefromstring($variant))->toMatchArray([0 => 800, 1 => 600, 'mime' => 'image/webp'])
        ->and($this->disk->exists('fastleo/variants/card/uploads/photo.jpg/2000.webp'))->toBeFalse();
});

it('keeps the proportions of the picture without a ratio', function (): void {
    putImage('uploads/photo.jpg', 1000, 500);

    expect(Variants::generate('uploads/photo.jpg', 'small'))->toBe([200 => 100, 300 => 150]);
});

it('puts the watermark only on the variants', function (): void {
    putImage('uploads/photo.jpg', 600, 600);
    $original = $this->disk->get('uploads/photo.jpg');
    $mark = imagecreatetruecolor(100, 100);
    imagefill($mark, 0, 0, (int) imagecolorallocate($mark, 255, 0, 0));
    ob_start();
    imagepng($mark);
    $this->disk->put('uploads/mark.png', (string) ob_get_clean());
    config(['fastleo.images.watermark' => ['path' => 'uploads/mark.png', 'position' => 'bottom-right', 'opacity' => 100, 'size' => 20]]);

    Variants::generate('uploads/photo.jpg', 'wide');

    $variant = imagecreatefromstring((string) $this->disk->get('fastleo/variants/wide/uploads/photo.jpg/300.webp'));
    $corner = imagecolorsforindex($variant, (int) imagecolorat($variant, 280, 280));
    $centre = imagecolorsforindex($variant, (int) imagecolorat($variant, 150, 150));

    expect($corner['red'])->toBeGreaterThan(200)->and($corner['green'])->toBeLessThan(60)
        ->and($centre['red'])->toBeLessThan(60)
        ->and($this->disk->get('uploads/photo.jpg'))->toBe($original);
});

it('ignores a watermark outside the uploads root', function (): void {
    putImage('uploads/photo.jpg', 600, 600);
    putImage('secret.png', 100, 100);
    config(['fastleo.images.watermark.path' => '../secret.png']);

    expect(Variants::generate('uploads/photo.jpg', 'wide'))->toBe([300 => 300]);
});

it('makes nothing for a missing or broken picture and removes old variants', function (): void {
    putImage('uploads/photo.jpg', 600, 600);
    Variants::generate('uploads/photo.jpg', 'small');
    $this->disk->put('uploads/photo.jpg', 'broken');

    expect(Variants::generate('uploads/photo.jpg', 'small'))->toBe([])
        ->and(Variants::generate('uploads/missing.jpg', 'small'))->toBe([])
        ->and($this->disk->exists('fastleo/variants/small/uploads/photo.jpg'))->toBeFalse();
});

it('touches nothing on the disk for a path outside the uploads root', function (): void {
    $this->disk->put('fastleo/variants/small/keep.txt', 'x');
    $this->disk->put('uploads/photo.jpg', 'x');

    expect(Variants::generate('fastleo/variants/small/../../../uploads', 'small'))->toBe([])
        ->and(Variants::generate('../uploads', 'small'))->toBe([])
        ->and($this->disk->exists('uploads/photo.jpg'))->toBeTrue()
        ->and($this->disk->exists('fastleo/variants/small/keep.txt'))->toBeTrue();
});

it('reads the sizes from the cache and, after it is cleared, from the disk', function (): void {
    putImage('uploads/photo.jpg', 600, 600);
    Variants::generate('uploads/photo.jpg', 'small');
    Cache::flush();

    expect(Variants::sizes('uploads/photo.jpg', 'small'))->toBe([200 => 200, 300 => 300])
        ->and(Variants::sizes('uploads/other.jpg', 'small'))->toBe([]);
});

it('renders a picture with webp sources once variants exist and the original before', function (): void {
    putImage('uploads/photo.jpg', 600, 600);
    $render = fn (): string => Blade::render('<x-fastleo::picture :src="$src" preset="small" alt="Врач" sizes="200px" class="rounded" />', ['src' => 'uploads/photo.jpg']);

    expect($render())->toContain('<img src="'.$this->disk->url('uploads/photo.jpg').'" alt="Врач"')
        ->toContain('class="rounded"')
        ->toContain('loading="lazy"')
        ->not->toContain('<picture>');

    Variants::generate('uploads/photo.jpg', 'small');

    expect($render())->toContain('<picture>')
        ->toContain('srcset="'.$this->disk->url('fastleo/variants/small/uploads/photo.jpg/200.webp').' 200w, '.$this->disk->url('fastleo/variants/small/uploads/photo.jpg/300.webp').' 300w"')
        ->toContain('sizes="200px"')
        ->toContain('width="300" height="300"')
        ->and(Blade::render('<x-fastleo::picture :src="null" preset="small" />'))->toBe('');
});

it('stops at an unknown or broken preset', function (): void {
    config(['fastleo.images.presets.bad' => ['widths' => [], 'ratio' => '4x3']]);

    expect(fn (): Image => Image::make('photo')->variants('nope'))->toThrow(LogicException::class, 'Размера картинок «nope» нет')
        ->and(fn (): array => Variants::preset('bad'))->toThrow(LogicException::class, 'описан неверно')
        ->and(fn () => Blade::render('<x-fastleo::picture src="uploads/a.jpg" preset="nope" />'))->toThrow(ViewException::class, 'Размера картинок «nope» нет');
});

it('removes variants with the file in the file manager', function (): void {
    putImage('uploads/old/photo.jpg', 600, 600);
    putImage('uploads/keep.jpg', 600, 600);
    Variants::generate('uploads/old/photo.jpg', 'small');
    Variants::generate('uploads/keep.jpg', 'small');
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->deleteJson(route('fastleo.files.destroy'), ['paths' => ['uploads/old']])->assertOk();

    expect($this->disk->exists('fastleo/variants/small/uploads/old'))->toBeFalse()
        ->and($this->disk->exists('fastleo/variants/small/uploads/keep.jpg/200.webp'))->toBeTrue()
        ->and(Variants::sizes('uploads/old/photo.jpg', 'small'))->toBe([]);
});

describe('saving a record', function (): void {
    beforeEach(function (): void {
        $this->actingAs(admin(), Fastleo::GUARD);
    });

    it('queues variants of new pictures after the save', function (): void {
        Queue::fake();

        $this->post(route('fastleo.resources.store', 'articles'), articleData([
            'cover' => 'uploads/articles/cover.jpg',
            'gallery' => ['uploads/articles/1.jpg', 'uploads/articles/2.jpg'],
            'banner' => ['title' => 'Акция', 'image' => 'uploads/banners/sale.jpg'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateImageVariants::class, 4);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/articles/cover.jpg' && $job->presets === ['card']);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/articles/2.jpg' && $job->presets === ['photo']);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/banners/sale.jpg');
    });

    it('queues only pictures that changed', function (): void {
        $article = Article::factory()->create(['cover' => 'uploads/a.jpg', 'gallery' => ['uploads/1.jpg']]);
        Queue::fake();

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData([
            'cover' => 'uploads/a.jpg',
            'gallery' => ['uploads/2.jpg', 'uploads/1.jpg'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateImageVariants::class, 1);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'uploads/2.jpg');
    });

    it('queues nothing when the pictures stayed the same', function (): void {
        $article = Article::factory()->create(['cover' => 'uploads/a.jpg', 'gallery' => ['uploads/1.jpg', 'uploads/2.jpg']]);
        Queue::fake();

        $this->put(route('fastleo.resources.update', ['articles', $article->id]), articleData([
            'title' => 'Новый заголовок',
            'cover' => 'uploads/a.jpg',
            'gallery' => ['uploads/1.jpg', 'uploads/2.jpg'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertNothingPushed();
    });

    it('makes the variants right away without a queue worker', function (): void {
        putImage('uploads/articles/cover.jpg', 1600, 900);

        $this->post(route('fastleo.resources.store', 'articles'), articleData(['cover' => 'uploads/articles/cover.jpg']))->assertSessionHasNoErrors();

        expect(Variants::sizes('uploads/articles/cover.jpg', 'card'))->toBe([400 => 225, 800 => 450]);
    })->with([fn () => config(['fastleo.images.presets' => ['card' => ['widths' => [400, 800], 'ratio' => '16:9'], 'photo' => ['widths' => [480]]]])]);
});

describe('fastleo:images', function (): void {
    it('remakes the variants of every picture of every field', function (): void {
        putImage('uploads/a.jpg', 1000, 600);
        putImage('uploads/b.jpg', 1000, 600);
        putImage('uploads/c.jpg', 1000, 600);
        Article::factory()->create(['cover' => 'uploads/a.jpg', 'gallery' => ['uploads/b.jpg']])
            ->banner()->create(['title' => 'x', 'image' => 'uploads/c.jpg']);
        Article::factory()->create(['cover' => null])->delete();
        config(['fastleo.images.presets' => ['card' => ['widths' => [400]], 'photo' => ['widths' => [300]]]]);

        $this->artisan('fastleo:images')->expectsOutputToContain('пересозданы: 3')->assertSuccessful();

        expect(Variants::sizes('uploads/a.jpg', 'card'))->toBe([400 => 240])
            ->and(Variants::sizes('uploads/b.jpg', 'photo'))->toBe([300 => 180])
            ->and(Variants::sizes('uploads/c.jpg', 'card'))->toBe([400 => 240]);
    });

    it('remakes only the given preset', function (): void {
        putImage('uploads/a.jpg', 1000, 600);
        putImage('uploads/b.jpg', 1000, 600);
        Article::factory()->create(['cover' => 'uploads/a.jpg', 'gallery' => ['uploads/b.jpg']]);
        config(['fastleo.images.presets' => ['card' => ['widths' => [400]], 'photo' => ['widths' => [300]]]]);

        $this->artisan('fastleo:images', ['--preset' => ['photo']])->expectsOutputToContain('пересозданы: 1')->assertSuccessful();

        expect(Variants::sizes('uploads/a.jpg', 'card'))->toBe([]);
    });
});
