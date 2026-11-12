<?php

declare(strict_types=1);

use Fastleo\Installer\WebRoutes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // Пишем в временную папку: общий каркас Testbench читают параллельные процессы тестов.
    $this->sandbox = sys_get_temp_dir().'/fastleo-web-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/routes');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'моя главная');\n");
    $this->app->setBasePath($this->sandbox);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

/**
 * `routes/web.php` нового Laravel с примером главной страницы.
 */
function welcomeRoutes(string $eol = "\n"): string
{
    return str_replace("\n", $eol, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n\nRoute::get('/about', fn () => 'о нас');\n");
}

/**
 * Код маршрутов модуля так, как он лежит в заготовке: без `<?php` и без `use` (имена классов полные).
 */
function newsBlock(string $uri = '/news-block'): string
{
    return "\\Illuminate\\Support\\Facades\\Route::get('".$uri."', fn (): string => 'новости');";
}

it('appends the routes of a module to the end without a single comment and keeps the routes of the site', function (): void {
    $web = new WebRoutes();

    expect($web->has(newsBlock()))->toBeFalse()
        ->and($web->append(['news' => newsBlock()], false))->toBe(['appended' => ['news'], 'welcomeReplaced' => false])
        ->and($web->has(newsBlock()))->toBeTrue();

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->toContain("Route::get('/', fn () => 'моя главная');\n\n".newsBlock()."\n")
        ->and($routes)->toStartWith("<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n")
        ->and($routes)->not->toContain('//')
        ->and($routes)->not->toContain('fastleo');
});

it('appends several modules in one write, a blank line between the blocks', function (): void {
    expect(new WebRoutes()->append(['news' => newsBlock(), 'faq' => newsBlock('/faq-block')], false)['appended'])->toBe(['news', 'faq']);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(strpos($routes, newsBlock()))->toBeLessThan(strpos($routes, newsBlock('/faq-block')))
        ->and($routes)->toContain(newsBlock()."\n\n".newsBlock('/faq-block')."\n");
});

it('does not append the code that is already in the file, but appends the others', function (): void {
    $web = new WebRoutes();
    $web->append(['news' => newsBlock()], false);
    $before = File::get($this->sandbox.'/routes/web.php');

    expect($web->append(['news' => newsBlock()], false))->toBe(['appended' => [], 'welcomeReplaced' => false])
        ->and(File::get($this->sandbox.'/routes/web.php'))->toBe($before)
        ->and($web->append(['news' => newsBlock(), 'faq' => newsBlock('/faq-block')], false)['appended'])->toBe(['faq'])
        ->and(substr_count(File::get($this->sandbox.'/routes/web.php'), newsBlock()))->toBe(1);
});

it('finds the code of a module in a file with other line breaks', function (): void {
    File::put($this->sandbox.'/routes/web.php', "<?php\r\n\r\n".str_replace("\n", "\r\n", newsBlock()."\n".newsBlock('/news-two'))."\r\n");

    expect(new WebRoutes()->has(newsBlock()."\n".newsBlock('/news-two')))->toBeTrue()
        ->and(new WebRoutes()->append(['news' => newsBlock()."\n".newsBlock('/news-two')], false)['appended'])->toBe([]);
});

it('does not write the file when there is nothing to change', function (): void {
    new WebRoutes()->append(['news' => newsBlock()], false);
    chmod($this->sandbox.'/routes/web.php', 0o444);

    $result = new WebRoutes()->append(['news' => newsBlock()], true);

    chmod($this->sandbox.'/routes/web.php', 0o644);

    expect($result)->toBe(['appended' => [], 'welcomeReplaced' => false]);
});

it('creates routes/web.php when the site has none', function (): void {
    File::delete($this->sandbox.'/routes/web.php');

    expect(new WebRoutes()->append(['news' => newsBlock()], false)['appended'])->toBe(['news'])
        ->and(File::get($this->sandbox.'/routes/web.php'))->toStartWith("<?php\n\n".newsBlock());
});

it('keeps the block of the fallback route the last one even when its module was installed first', function (): void {
    $web = new WebRoutes();
    $web->append(['pages' => newsBlock('/pages-fallback')], false, [newsBlock('/pages-fallback')]);
    $web->append(['news' => newsBlock(), 'faq' => newsBlock('/faq-block')], false, [newsBlock('/pages-fallback')]);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(strpos($routes, newsBlock()))->toBeLessThan(strpos($routes, newsBlock('/faq-block')))
        ->and(strpos($routes, newsBlock('/faq-block')))->toBeLessThan(strpos($routes, newsBlock('/pages-fallback')))
        ->and(rtrim($routes))->toEndWith(newsBlock('/pages-fallback'))
        ->and($routes)->toContain(newsBlock('/faq-block')."\n\n".newsBlock('/pages-fallback')."\n");
});

it('puts the fallback block after the others when both are installed in one go, in any order', function (): void {
    expect(new WebRoutes()->append(['pages' => newsBlock('/pages-fallback'), 'news' => newsBlock()], false, [newsBlock('/pages-fallback')])['appended'])->toBe(['news', 'pages']);

    expect(rtrim(File::get($this->sandbox.'/routes/web.php')))->toEndWith(newsBlock('/pages-fallback'));
});

it('inserts the routes above the fallback block that the site has code after, and keeps its own code in place', function (): void {
    $web = new WebRoutes();
    $web->append(['pages' => newsBlock('/pages-fallback')], false, [newsBlock('/pages-fallback')]);
    File::append($this->sandbox.'/routes/web.php', "\nRoute::get('/after', fn () => 'после');\n");

    $web->append(['news' => newsBlock()], false, [newsBlock('/pages-fallback')]);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->toContain(newsBlock()."\n\n".newsBlock('/pages-fallback')."\n")
        ->and(strpos($routes, newsBlock()))->toBeLessThan(strpos($routes, newsBlock('/pages-fallback')))
        ->and(strpos($routes, newsBlock('/pages-fallback')))->toBeLessThan(strpos($routes, "Route::get('/after'"))
        ->and(substr_count($routes, newsBlock('/pages-fallback')))->toBe(1);
});

it('appends the routes inside the code of a file that ends with a closing tag', function (): void {
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nRoute::get('/', fn () => 'главная');\n?>\n");

    new WebRoutes()->append(['news' => newsBlock()], false);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->not->toContain('?>')
        ->and($routes)->toContain(newsBlock());
});

it('keeps the Windows line breaks of the file', function (): void {
    File::put($this->sandbox.'/routes/web.php', "<?php\r\n\r\nRoute::get('/', fn () => 'главная');\r\n");

    new WebRoutes()->append(['news' => newsBlock()."\r\n".newsBlock('/news-two')], false);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(str_replace("\r\n", '', $routes))->not->toContain("\n")
        ->and($routes)->toContain(str_replace("\n", "\r\n", newsBlock()."\n".newsBlock('/news-two'))."\r\n");
});

it('removes the untouched welcome route of a new Laravel together with the appended routes in one write', function (): void {
    File::put($this->sandbox.'/routes/web.php', welcomeRoutes());

    expect(new WebRoutes()->append(['pages' => newsBlock('/')], true))->toBe(['appended' => ['pages'], 'welcomeReplaced' => true]);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->not->toContain("view('welcome')")
        ->and($routes)->toContain("Route::get('/about', fn () => 'о нас');")
        ->and($routes)->toContain(newsBlock('/'));
});

it('removes the welcome route of a file with Windows line breaks and keeps the next route', function (): void {
    File::put($this->sandbox.'/routes/web.php', welcomeRoutes("\r\n"));

    new WebRoutes()->append(['pages' => newsBlock('/')], true);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->not->toContain("view('welcome')")
        ->and($routes)->toContain("Route::get('/about', fn () => 'о нас');")
        ->and(str_replace("\r\n", '', $routes))->not->toContain("\n");
});

it('removes the welcome route of a site whose module is already in the file without appending it twice', function (): void {
    new WebRoutes()->append(['pages' => newsBlock('/')], false);
    File::put($this->sandbox.'/routes/web.php', welcomeRoutes().File::get($this->sandbox.'/routes/web.php'));

    expect(new WebRoutes()->append(['pages' => newsBlock('/')], true))->toBe(['appended' => [], 'welcomeReplaced' => true])
        ->and(substr_count(File::get($this->sandbox.'/routes/web.php'), newsBlock('/')))->toBe(1);
});

it('leaves the welcome route alone unless it is asked to replace it', function (): void {
    File::put($this->sandbox.'/routes/web.php', welcomeRoutes());

    expect(new WebRoutes()->append(['news' => newsBlock()], false)['welcomeReplaced'])->toBeFalse()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain("view('welcome')");
});

it('does not take a commented out welcome route for an untouched one and keeps the next route', function (): void {
    File::put($this->sandbox.'/routes/web.php', "<?php\n\n// Route::get('/', function () { return view('welcome'); });\nRoute::get('/about', fn () => 'о нас');\n");

    expect(new WebRoutes()->append(['news' => newsBlock()], true)['welcomeReplaced'])->toBeFalse()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain("// Route::get('/', function () { return view('welcome'); });\nRoute::get('/about'");
});

it('does not touch a welcome route inside a group of the site', function (): void {
    $routes = "<?php\n\nRoute::middleware('auth')->group(function () {\n    Route::get('/', function () {\n        return view('welcome');\n    });\n});\n";
    File::put($this->sandbox.'/routes/web.php', $routes);

    expect(new WebRoutes()->append(['news' => newsBlock()], true)['welcomeReplaced'])->toBeFalse()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain($routes);
});

it('keeps a home route of the site that is not the example of Laravel', function (): void {
    expect(new WebRoutes()->append(['news' => newsBlock()], true)['welcomeReplaced'])->toBeFalse()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain('моя главная');
});

it('starts a routes file that holds only blank lines with the opening PHP tag', function (): void {
    File::put($this->sandbox.'/routes/web.php', "\n");

    new WebRoutes()->append(['news' => newsBlock()], false);

    expect(File::get($this->sandbox.'/routes/web.php'))->toStartWith("<?php\n")
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain(newsBlock());
});

it('removes the welcome route while the welcome template is the one of a new Laravel', function (): void {
    File::ensureDirectoryExists(resource_path('views'));
    File::put(resource_path('views/welcome.blade.php'), '<a href="https://laravel.com/docs">Documentation</a>');
    File::put($this->sandbox.'/routes/web.php', welcomeRoutes());

    expect(new WebRoutes()->append(['pages' => newsBlock('/')], true)['welcomeReplaced'])->toBeTrue();
});

it('keeps the welcome route when the site rewrote the welcome template into its own home page', function (): void {
    File::ensureDirectoryExists(resource_path('views'));
    File::put(resource_path('views/welcome.blade.php'), '<h1>Наш лендинг</h1>');
    File::put($this->sandbox.'/routes/web.php', welcomeRoutes());

    expect(new WebRoutes()->append(['pages' => newsBlock('/')], true))->toBe(['appended' => ['pages'], 'welcomeReplaced' => false])
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain("view('welcome')");
});

it('writes a file that works whatever is already imported at its top and whatever the path of the project holds', function (): void {
    // Весь код модулей — с полными именами классов: свой `use` в файле сайта (тот же `Route`) не даёт повторного импорта.
    $project = $this->sandbox.'/[client] site';
    File::ensureDirectoryExists($project.'/routes');
    File::put($project.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/mine', fn (): string => 'моё');\n");
    $this->app->setBasePath($project);

    new WebRoutes()->append(['news' => newsBlock('/bracket-ok'), 'faq' => newsBlock('/faq-ok')], false);
    Route::middleware('web')->group($project.'/routes/web.php');

    $this->get('/mine')->assertOk();
    $this->get('/bracket-ok')->assertOk();
    $this->get('/faq-ok')->assertOk();
});

it('recognises a block whose class names Pint shortened, does not write it twice and inserts new routes above it', function (): void {
    $web = new WebRoutes();
    $landing = "\\Illuminate\\Support\\Facades\\Route::get('/{landing?}', [\\App\\Http\\Controllers\\LandingController::class, 'show'])->name('site.landing.show');";
    // Так файл выглядит после `pint` с пресетом Laravel: импорты наверху, имена сокращены.
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nuse App\\Http\\Controllers\\LandingController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'моя главная');\n\nRoute::get('/{landing?}', [LandingController::class, 'show'])->name('site.landing.show');\n");

    expect($web->has($landing))->toBeTrue()
        ->and($web->has(str_replace('landing', 'other', $landing)))->toBeFalse()
        ->and($web->append(['landing' => $landing], false, [$landing])['appended'])->toBe([]);

    $web->append(['news' => newsBlock()], false, [$landing]);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(strpos($routes, newsBlock()))->toBeLessThan(strpos($routes, "Route::get('/{landing?}'"))
        ->and(substr_count($routes, 'site.landing.show'))->toBe(1);
});

it('does not take a commented out block for an installed one', function (): void {
    $web = new WebRoutes();
    File::put($this->sandbox.'/routes/web.php', "<?php\n\n// ".newsBlock()."\n# ".newsBlock('/hash')."\n/* ".newsBlock('/multi')." */\n");

    expect($web->has(newsBlock()))->toBeFalse()
        ->and($web->has(newsBlock('/hash')))->toBeFalse()
        ->and($web->has(newsBlock('/multi')))->toBeFalse()
        ->and($web->append(['news' => newsBlock()], false)['appended'])->toBe(['news'])
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain("\n".newsBlock()."\n");
});

it('inserts new routes above the whole group of the site when the catch-all block sits inside that group', function (): void {
    $web = new WebRoutes();
    $fallback = newsBlock('/pages-fallback');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nRoute::get('/', fn () => 'главная');\n\nRoute::middleware('auth')->group(function (): void {\n    ".$fallback."\n});\n\nRoute::get('/after', fn () => 'после');\n");

    $web->append(['news' => newsBlock()], false, [$fallback]);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect(strpos($routes, newsBlock()))->toBeLessThan(strpos($routes, "Route::middleware('auth')"))
        ->and(strpos($routes, "Route::get('/', fn"))->toBeLessThan(strpos($routes, newsBlock()))
        ->and($routes)->toContain(newsBlock()."\n\nRoute::middleware('auth')->group(function (): void {\n    ".$fallback."\n});")
        ->and(substr_count($routes, newsBlock()))->toBe(1);
});

it('inserts new routes before the fallback block of a file written in one line, not before the opening tag', function (): void {
    $web = new WebRoutes();
    $fallback = newsBlock('/pages-fallback');
    File::put($this->sandbox.'/routes/web.php', '<?php '.$fallback);

    $web->append(['news' => newsBlock()], false, [$fallback]);

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->toStartWith('<?php')
        ->and(strpos($routes, newsBlock()))->toBeLessThan(strpos($routes, $fallback))
        ->and(substr_count($routes, $fallback))->toBe(1);
});

it('recognises a block of several lines after the site added a blank line or the indent of a group', function (): void {
    $web = new WebRoutes();
    $block = newsBlock()."\n".newsBlock('/news-more');
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nRoute::middleware('web')->group(function (): void {\n    ".newsBlock()."\n\n    ".newsBlock('/news-more')."\n});\n");

    expect($web->has($block))->toBeTrue()
        ->and($web->append(['news' => $block], false)['appended'])->toBe([])
        ->and($web->has(newsBlock('/other')))->toBeFalse();
});
