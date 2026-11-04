<?php

declare(strict_types=1);

use Fastleo\Installer\WebRoutes;
use Illuminate\Support\Facades\File;

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

it('connects the site routes only once and keeps the routes of the site', function (): void {
    expect(new WebRoutes()->ensureSiteRoutes())->toBeTrue()
        ->and(new WebRoutes()->ensureSiteRoutes())->toBeFalse()
        ->and(substr_count(File::get($this->sandbox.'/routes/web.php'), 'require $siteRoutes'))->toBe(1)
        ->and(File::get($this->sandbox.'/routes/web.php'))->toContain('моя главная');
});

it('creates routes/web.php when the site has none', function (): void {
    File::delete($this->sandbox.'/routes/web.php');

    expect(new WebRoutes()->ensureSiteRoutes())->toBeTrue()
        ->and(File::get($this->sandbox.'/routes/web.php'))->toStartWith('<?php');
});

it('connects the routes inside the code of a file that ends with a closing tag', function (): void {
    File::put($this->sandbox.'/routes/web.php', "<?php\n\nRoute::get('/', fn () => 'главная');\n?>\n");

    new WebRoutes()->ensureSiteRoutes();

    $routes = File::get($this->sandbox.'/routes/web.php');

    expect($routes)->not->toContain('?>')
        ->and($routes)->toContain('require $siteRoutes');
});

it('keeps the Windows line breaks of the file', function (): void {
    File::put($this->sandbox.'/routes/web.php', "<?php\r\n\r\nRoute::get('/', fn () => 'главная');\r\n");

    new WebRoutes()->ensureSiteRoutes();

    expect(str_replace("\r\n", '', File::get($this->sandbox.'/routes/web.php')))->not->toContain("\n");
});
