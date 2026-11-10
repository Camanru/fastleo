<?php

declare(strict_types=1);

use App\FastleoSections\Models\MenuItem;
use Fastleo\Fastleo;
use Fastleo\Installer\Modules;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Модули «Меню сайта», «Отзывы», «Портфолио», «Вопросы и ответы»: код копируется в сайт и поднимается по-настоящему.
 */
const SECTION_MODULES = [
    'menu' => ['table' => 'menu_items', 'slug' => 'menu-items', 'resource' => 'MenuItemResource', 'model' => 'MenuItem', 'store' => ['title' => 'Контакты', 'url' => '/contacts', 'location' => 'header', 'is_published' => '1']],
    'reviews' => ['table' => 'reviews', 'slug' => 'reviews', 'resource' => 'ReviewResource', 'model' => 'Review', 'store' => ['author' => 'Анна', 'rating' => '5', 'body' => 'Спасибо!', 'is_published' => '1']],
    'portfolio' => ['table' => 'portfolio_items', 'slug' => 'portfolio', 'resource' => 'PortfolioResource', 'model' => 'PortfolioItem', 'store' => ['title' => 'Работа', 'slug' => 'rabota', 'is_published' => '1']],
    'faq' => ['table' => 'faq_items', 'slug' => 'faq', 'resource' => 'FaqResource', 'model' => 'FaqItem', 'store' => ['question' => 'Как заказать?', 'answer' => '<p>Позвоните.</p>', 'is_published' => '1']],
];

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-sections-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    File::put($this->sandbox.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($this->sandbox);

    foreach (SECTION_MODULES as $module) {
        Schema::dropIfExists($module['table']);
    }

    config(['fastleo.resources.path' => $this->sandbox.'/app/Fastleo', 'fastleo.resources.namespace' => 'App\\FastleoSections']);
});

afterEach(function (): void {
    Factory::flushState();
    File::deleteDirectory($this->sandbox);
});

/**
 * Скопировать модуль в сайт и поднять его: миграция, классы (один раз на процесс), фабрики.
 */
function bootCopiedSection(object $test, string $name): void
{
    $module = SECTION_MODULES[$name];

    $test->artisan('fastleo:module', ['modules' => [$name]])->assertSuccessful();
    $test->artisan('migrate', ['--path' => $test->sandbox.'/database/migrations', '--realpath' => true])->assertSuccessful();

    foreach (['app/Fastleo/Models/'.$module['model'] => 'App\\FastleoSections\\Models\\'.$module['model'], 'app/Fastleo/'.$module['resource'] => 'App\\FastleoSections\\'.$module['resource'], 'database/factories/Fastleo/'.$module['model'].'Factory' => 'Database\\Factories\\Fastleo\\'.$module['model'].'Factory'] as $file => $class) {
        if (! class_exists($class, false)) {
            require $test->sandbox.'/'.$file.'.php';
        }
    }

    Fastleo::resources()->flush();
}

it('is in the list of modules with its own tables', function (): void {
    expect(array_keys(Modules::all()))->toBe(['contacts', 'pages', 'news', 'menu', 'reviews', 'portfolio', 'faq', 'shop'])
        ->and(Modules::find('menu')->tables)->toBe(['menu_items'])
        ->and(Modules::find('faq')->tables)->toBe(['faq_items']);
});

it('copies the module with its migration, factory, seeder and test and touches nothing else', function (string $name): void {
    $module = SECTION_MODULES[$name];

    $this->artisan('fastleo:module', ['modules' => [$name]])
        ->expectsOutputToContain('app/Fastleo/Models/'.$module['model'].'.php')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app/Fastleo/'.$module['resource'].'.php'))->toBeTrue()
        ->and(glob($this->sandbox.'/database/migrations/*_create_'.$module['table'].'_table.php'))->toHaveCount(1)
        ->and(File::exists($this->sandbox.'/database/factories/Fastleo/'.$module['model'].'Factory.php'))->toBeTrue()
        ->and(File::exists($this->sandbox.'/database/seeders/Fastleo/'.$module['model'].'Seeder.php'))->toBeTrue()
        ->and($this->sandbox.'/routes/site')->not->toBeDirectory()
        ->and($this->sandbox.'/app/Fastleo/Dashboard.php')->not->toBeFile();
})->with(array_keys(SECTION_MODULES));

it('does not install a module over an existing table of the site', function (string $name): void {
    Schema::create(SECTION_MODULES[$name]['table'], function ($table): void {
        $table->id();
    });

    $this->artisan('fastleo:module', ['modules' => [$name]])->assertFailed();

    expect($this->sandbox.'/app/Fastleo')->not->toBeDirectory();
})->with(array_keys(SECTION_MODULES));

it('gives the administrator a working section that passes fastleo:doctor', function (string $name): void {
    $module = SECTION_MODULES[$name];
    bootCopiedSection($this, $name);
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->get(route('fastleo.resources.index', $module['slug']))->assertOk();
    $this->get(route('fastleo.resources.create', $module['slug']))->assertOk();
    $this->post(route('fastleo.resources.store', $module['slug']), $module['store'])->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table($module['table'])->count())->toBe(1);

    Artisan::call('fastleo:doctor', ['--json' => true]);
    $problems = collect(json_decode(Artisan::output(), true)['problems'])->where('scope', $module['slug'])->where('level', 'error');

    expect($problems->all())->toBe([]);
})->with(array_keys(SECTION_MODULES));

it('shows published reviews, works and questions in the order of the panel', function (string $name, string $column, string $first, string $second): void {
    bootCopiedSection($this, $name);
    $model = 'App\\FastleoSections\\Models\\'.SECTION_MODULES[$name]['model'];

    $model::factory()->create([$column => $second, 'sort' => 2]);
    $model::factory()->create([$column => $first, 'sort' => 1]);
    $model::factory()->create([$column => 'Скрытая запись', 'is_published' => false, 'sort' => 0]);

    expect($model::query()->published()->pluck($column)->all())->toBe([$first, $second]);
})->with([
    'reviews' => ['reviews', 'author', 'Первый', 'Второй'],
    'portfolio' => ['portfolio', 'title', 'Первая', 'Вторая'],
    'faq' => ['faq', 'question', 'Первый?', 'Второй?'],
]);

it('builds the menu tree from published items of one place only', function (): void {
    bootCopiedSection($this, 'menu');

    $about = MenuItem::factory()->create(['title' => 'О нас', 'url' => '/about', 'sort' => 2]);
    MenuItem::factory()->create(['title' => 'Главная', 'url' => '/', 'sort' => 1]);
    MenuItem::factory()->create(['title' => 'Команда', 'url' => '/team', 'parent_id' => $about->id]);
    MenuItem::factory()->create(['title' => 'Скрытый', 'url' => '/hidden', 'is_published' => false, 'sort' => 3]);
    MenuItem::factory()->create(['title' => 'Политика', 'url' => '/privacy', 'location' => MenuItem::FOOTER]);

    $tree = MenuItem::tree('header');

    expect($tree->pluck('title')->all())->toBe(['Главная', 'О нас'])
        ->and($tree->first()->children->all())->toBe([])
        ->and($tree->last()->children->pluck('title')->all())->toBe(['Команда'])
        ->and(MenuItem::tree(MenuItem::FOOTER)->pluck('title')->all())->toBe(['Политика']);
});

it('leaves out the items of a hidden parent of the menu', function (): void {
    bootCopiedSection($this, 'menu');
    $hidden = MenuItem::factory()->create(['title' => 'Скрытый', 'is_published' => false]);
    MenuItem::factory()->create(['title' => 'Вложенный', 'parent_id' => $hidden->id]);

    expect(MenuItem::tree()->all())->toBe([]);
});

it('refuses a menu address that is not a path of the site or a web address', function (): void {
    bootCopiedSection($this, 'menu');
    $this->actingAs(admin(), Fastleo::GUARD);

    foreach (['javascript:alert(1)', '//evil.example', 'contacts'] as $url) {
        $this->post(route('fastleo.resources.store', 'menu-items'), ['title' => 'Пункт', 'url' => $url, 'location' => 'header'])->assertSessionHasErrors('url');
    }

    $this->post(route('fastleo.resources.store', 'menu-items'), ['title' => 'Пункт', 'url' => 'https://example.com/a', 'location' => 'footer'])->assertSessionHasNoErrors();

    expect(MenuItem::query()->count())->toBe(1);
});

it('accepts only a whole rating from 1 to 5', function (): void {
    bootCopiedSection($this, 'reviews');
    $this->actingAs(admin(), Fastleo::GUARD);

    foreach (['4.5', '0', '6'] as $rating) {
        $this->post(route('fastleo.resources.store', 'reviews'), ['author' => 'Анна', 'rating' => $rating, 'body' => 'Спасибо!'])->assertSessionHasErrors('rating');
    }

    expect(DB::table('reviews')->count())->toBe(0);
});

it('puts the sections of the modules in the top of the menu, without a group (the shop has its own)', function (): void {
    $stubs = array_values(array_filter(glob(dirname(__DIR__, 3).'/stubs/modules/*/fastleo/*Resource.php.stub'), fn (string $stub): bool => ! str_contains($stub, '/shop/')));

    expect($stubs)->toHaveCount(6);

    foreach ($stubs as $stub) {
        expect(File::get($stub))->not->toContain('$group');
    }
});

it('tells the next steps after copying: migrate and, if wanted, the seeders of the added modules', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['menu', 'faq']])
        ->expectsOutputToContain('Дальше:')
        ->expectsOutputToContain('php artisan migrate')
        ->expectsOutputToContain('php artisan db:seed --class=\'Database\\Seeders\\Fastleo\\MenuItemSeeder\'; php artisan db:seed --class=\'Database\\Seeders\\Fastleo\\FaqItemSeeder\'')
        ->assertSuccessful();
});

it('does not tell about migrations or seeders after a module without tables', function (): void {
    $this->artisan('fastleo:module', ['modules' => ['contacts']])
        ->doesntExpectOutputToContain('Дальше:')
        ->assertSuccessful();
});
