<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Fastleo\Fastleo;
use Fastleo\Notifications\AdminNotification;
use Fastleo\Notifications\FastleoMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\ChoiceSample;
use Workbench\App\Models\Feedback;
use Workbench\App\Models\JsonSample;
use Workbench\App\Models\MediaSample;
use Workbench\App\Models\Page;
use Workbench\App\Models\Product;
use Workbench\App\Models\ProductReview;
use Workbench\App\Models\Remark;
use Workbench\App\Models\Supplier;
use Workbench\App\Models\Task;
use Workbench\App\Models\TextSample;
use Workbench\App\Models\Topic;
use Workbench\App\Models\User;

/**
 * Данные демо-сайта: администратор admin@example.com / password, примеры записей и витрина возможностей.
 */
final class DatabaseSeeder extends Seeder
{
    /**
     * Наполнить базу демо-данными.
     */
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
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

        $suppliers = Supplier::factory()->count(6)->create();
        $products = Product::factory()->count(30)->create(['category_id' => fn () => $categories->random()->id, 'supplier_id' => fn () => $suppliers->random()->id]);
        $products->each(fn (Product $product) => ProductReview::factory()->count(random_int(0, 4))->create(['product_id' => $product->id]));
        Remark::factory()->count(3)->create();
        Remark::factory()->create(['subject_type' => Product::class, 'subject_id' => $products->first()?->id]);
        $products->take(3)->each(fn (Product $product) => $products->where('id', '!=', $product->id)->random(2)->values()->each(fn (Product $item, int $index) => $product->bundleItems()->create(['item_id' => $item->id, 'qty' => $index + 1, 'sort' => $index + 1])));

        $topics = Topic::factory()->count(15)->sequence(fn (Sequence $sequence): array => ['sort' => $sequence->index + 1])->create();
        Article::query()->each(fn (Article $article) => $article->topics()->attach($topics->random(2)->pluck('id')));

        $this->seedImages();
        $this->seedShowcase($suppliers);
        $this->seedNotifications($admin);

        Artisan::call('fastleo:images');
    }

    /**
     * Витрина возможностей: по две записи каждого раздела «Витрина: поля» (у файлов — картинки и документ)
     * и задачи за последний месяц для списка и отчёта.
     *
     * @param  EloquentCollection<int, Supplier>  $suppliers
     */
    private function seedShowcase(EloquentCollection $suppliers): void
    {
        TextSample::factory()->count(2)->create();
        TextSample::factory()->create(['title' => 'Пример с паролем и ключом', 'password' => 'password', 'api_key' => 'sk_demo_key']);
        ChoiceSample::factory()->count(3)->create();
        JsonSample::factory()->count(2)->create();

        $disk = Storage::disk('public');
        $disk->put('uploads/showcase-docs/price-list.pdf', "%PDF-1.4\n% Fastleo demo\n%%EOF\n");
        $pictures = [];

        foreach ([[79, 70, 229], [16, 185, 129], [245, 158, 11]] as $index => $color) {
            $disk->put($pictures[] = 'uploads/showcase/sample-'.($index + 1).'.jpg', $this->picture(1600, 900, $color, 'Showcase '.($index + 1)));
        }

        MediaSample::factory()->create([
            'title' => 'Все поля заполнены',
            'cover' => $pictures[0],
            'cover_alt' => 'Фиолетовая заглушка',
            'photo' => $pictures[1],
            'gallery' => $pictures,
            'document' => 'uploads/showcase-docs/price-list.pdf',
        ]);
        MediaSample::factory()->create(['title' => 'Только видео']);

        Task::factory()->count(20)->create([
            'supplier_id' => fn () => $suppliers->random()->id,
            'created_at' => fn () => now()->subDays(random_int(0, 28))->subMinutes(random_int(0, 600)),
        ]);
        Task::factory()->done()->count(8)->create(['supplier_id' => fn () => $suppliers->random()->id, 'created_at' => fn () => now()->subDays(random_int(0, 28))]);
        Task::factory()->overdue()->count(3)->create(['supplier_id' => fn () => $suppliers->random()->id, 'is_urgent' => true]);
    }

    /**
     * Уведомления администратора за последний месяц для колокольчика и раздела «Уведомления»: 45 штук (две страницы),
     * свежие — непрочитанные, все уровни, со ссылками на записи и без них.
     */
    private function seedNotifications(User $admin): void
    {
        $articles = Article::query()->orderBy('id')->limit(10)->get()->all();
        $products = Product::query()->orderBy('id')->limit(10)->get()->all();
        $kinds = [
            fn (int $n): FastleoMessage => FastleoMessage::make('Новая заявка')->body('Клиент '.$n.', +7 900 000-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).'-00')->icon('inbox')->level('success'),
            fn (int $n): FastleoMessage => FastleoMessage::make('Статья ждёт проверки')->body($articles[$n % count($articles)]->title)->url(Fastleo::recordUrl($articles[$n % count($articles)], false))->icon('file-text'),
            fn (int $n): FastleoMessage => FastleoMessage::make('Заканчивается товар')->body($products[$n % count($products)]->title.': осталось '.($n % 5 + 1).' шт.')->url(Fastleo::recordUrl($products[$n % count($products)], false))->icon('package')->level('warning'),
            fn (int $n): FastleoMessage => FastleoMessage::make('Импорт завершён')->body('Раздел «Товары»: создано '.($n * 3).', обновлено '.$n.', пропущено 0.')->icon('upload')->level('success'),
            fn (int $n): FastleoMessage => FastleoMessage::make('Публикация не отправлена')->body('Канал «Новости магазина»: бот не администратор канала.')->icon('send')->level('danger'),
        ];

        foreach (range(1, 45) as $n) {
            $createdAt = now()->subHours($n * 15 + random_int(0, 10))->subMinutes(random_int(0, 59));

            AdminNotification::query()->create([
                'user_id' => (string) $admin->id,
                'data' => $kinds[$n % count($kinds)]($n)->toArray(),
                'created_at' => $createdAt,
                'read_at' => $n <= 8 ? null : $createdAt->copy()->addHours(2),
            ]);
        }
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

        Product::query()->orderBy('id')->limit(6)->get()->each(function (Product $product, int $index) use ($disk, $colors): void {
            $disk->put($path = 'uploads/products/demo-'.($index + 1).'.jpg', $this->picture(800, 600, $colors[$index], 'Товар '.($index + 1)));
            $product->update(['photo' => $path]);
        });

        Page::factory()->count(3)->create();
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
