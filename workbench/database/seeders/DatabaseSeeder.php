<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\Feedback;
use Workbench\App\Models\Page;
use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

/**
 * Данные демо-сайта: администратор admin@example.com / password и примеры записей.
 */
final class DatabaseSeeder extends Seeder
{
    /**
     * Наполнить базу демо-данными.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Администратор',
            'email' => 'admin@example.com',
        ]);

        // Дерево категорий: 6 разделов по 3 подраздела, у первых подразделов — ещё по 2.
        $categories = Category::factory()->count(6)->sequence(fn (Sequence $sequence): array => ['sort' => $sequence->index + 1])->create();

        foreach ($categories->all() as $root) {
            $children = Category::factory()->count(3)->sequence(fn (Sequence $sequence): array => ['sort' => $sequence->index + 1])->create(['parent_id' => $root->id]);
            Category::factory()->count(2)->sequence(fn (Sequence $sequence): array => ['sort' => $sequence->index + 1])->create(['parent_id' => $children->first()?->id]);
        }

        $categories = Category::all();
        Article::factory()->count(20)->create(['category_id' => fn () => $categories->random()->id]);
        Article::factory()->published()->count(10)->create(['category_id' => fn () => $categories->random()->id]);
        Feedback::factory()->count(5)->create();

        $topics = Topic::factory()->count(15)->sequence(fn (Sequence $sequence): array => ['sort' => $sequence->index + 1])->create();
        Article::query()->each(fn (Article $article) => $article->topics()->attach($topics->random(2)->pluck('id')));

        $this->seedImages();
    }

    /**
     * Картинки демо: водяной знак, обложки и галерея статей, варианты для сайта.
     */
    private function seedImages(): void
    {
        $disk = Storage::disk('public');
        $disk->deleteDirectory('uploads');
        $disk->deleteDirectory('fastleo');
        $disk->put('uploads/watermark.png', $this->picture(240, 80, [255, 255, 255], 'fastleo', transparent: true));

        /** @var list<array{int<0, 255>, int<0, 255>, int<0, 255>}> $colors */
        $colors = [[79, 70, 229], [14, 165, 233], [16, 185, 129], [245, 158, 11], [239, 68, 68], [139, 92, 246]];
        $paths = [];

        foreach ($colors as $index => $color) {
            $paths[] = $path = 'uploads/articles/demo-'.($index + 1).'.jpg';
            $disk->put($path, $this->picture(1600, 900, $color, 'Demo '.($index + 1)));
        }

        Article::query()->orderBy('id')->limit(6)->get()->each(function (Article $article, int $index) use ($paths): void {
            $article->update(['cover' => $paths[$index], 'gallery' => array_values(array_diff($paths, [$paths[$index]]))]);
        });

        Page::factory()->count(3)->create();

        Artisan::call('fastleo:images');
    }

    /**
     * Картинка-заглушка: заливка цветом и подпись.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     * @param  array{int<0, 255>, int<0, 255>, int<0, 255>}  $color
     */
    private function picture(int $width, int $height, array $color, string $label, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $transparent ? (int) imagecolorallocatealpha($image, 0, 0, 0, 127) : (int) imagecolorallocate($image, ...$color));
        $text = (int) imagecolorallocatealpha($image, 255, 255, 255, $transparent ? 30 : 0);
        imagestring($image, 5, (int) ($width / 2 - strlen($label) * 4.5), (int) ($height / 2 - 8), $label, $text);

        ob_start();
        $transparent ? imagepng($image) : imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }
}
