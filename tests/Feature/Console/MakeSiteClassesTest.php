<?php

declare(strict_types=1);

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Dashboard;
use Fastleo\Fastleo;
use Fastleo\Fields\Text;
use Fastleo\Filters\Filter;
use Fastleo\Page;
use Fastleo\Settings;
use Fastleo\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Workbench\App\Models\Article;

beforeEach(function (): void {
    // Классы и шаблоны сайта — во временной папке с уникальным namespace.
    $this->sandbox = sys_get_temp_dir().'/fastleo-make-'.getmypid().'-'.uniqid();
    $this->namespace = 'Generated'.uniqid();
    File::ensureDirectoryExists($this->sandbox.'/views');
    config([
        'fastleo.resources.path' => $this->sandbox.'/Fastleo',
        'fastleo.resources.namespace' => $this->namespace,
        'view.paths' => [$this->sandbox.'/views'],
    ]);
    View::getFinder()->prependLocation($this->sandbox.'/views');
    Fastleo::resources()->flush();
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('creates an action that runs on records', function (): void {
    $this->artisan('fastleo:make-action', ['name' => 'PublishDoctors'])
        ->expectsOutputToContain('Класс создан:')
        ->assertSuccessful();

    $path = $this->sandbox.'/Fastleo/Actions/PublishDoctors.php';
    require $path;
    $class = $this->namespace.'\\Actions\\PublishDoctors';
    $action = new $class;

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->key())->toBe('publish-doctors')
        ->and($action->getLabel())->toBe('Publish Doctors')
        ->and($action->handle(new Collection([new Article, new Article]), []))->toEqual(ActionResult::success('Готово: 2'));
});

it('creates a filter with a view that narrows the list', function (): void {
    $this->artisan('fastleo:make-filter', ['name' => 'ByTitle'])->assertSuccessful();

    require $this->sandbox.'/Fastleo/Filters/ByTitle.php';
    $class = $this->namespace.'\\Filters\\ByTitle';
    $filter = $class::make('title', 'Название');

    expect($filter)->toBeInstanceOf(Filter::class)
        ->and($filter->value('abc'))->toBe('abc')
        ->and($filter->value(''))->toBeNull()
        ->and($filter->value(['x']))->toBeNull()
        ->and($filter->view())->toBe('fastleo.filters.by-title');

    Article::factory()->create(['title' => 'Alpha']);
    Article::factory()->create(['title' => 'Beta']);
    $query = Article::query();
    $filter->apply($query, 'Alpha');

    expect($query->pluck('title')->all())->toBe(['Alpha'])
        ->and(view($filter->view(), ['filter' => $filter, 'value' => 'Alpha', 'form' => 'list-form'])->render())
        ->toContain('name="filter[title]"', 'form="list-form"', 'value="Alpha"');
});

it('creates a widget with a view', function (): void {
    $this->artisan('fastleo:make-widget', ['name' => 'QueueSize'])->assertSuccessful();

    require $this->sandbox.'/Fastleo/Widgets/QueueSize.php';
    $class = $this->namespace.'\\Widgets\\QueueSize';
    $widget = $class::make('Очередь');

    expect($widget)->toBeInstanceOf(Widget::class)
        ->and($widget->view())->toBe('fastleo.widgets.queue-size')
        ->and(view($widget->view(), ['value' => 7])->render())->toContain('7');
});

it('creates a page that the registry finds and the admin opens', function (): void {
    $this->artisan('fastleo:make-page', ['name' => 'Report'])->assertSuccessful();

    expect($this->sandbox.'/Fastleo/ReportPage.php')->toBeFile();
    require $this->sandbox.'/Fastleo/ReportPage.php';
    $class = $this->namespace.'\\ReportPage';

    expect(Fastleo::resources()->pages())->toMatchArray(['report' => $class])
        ->and(new $class)->toBeInstanceOf(Page::class);

    $this->actingAs(admin(), 'fastleo')
        ->get(route('fastleo.pages.show', 'report'))
        ->assertOk()
        ->assertSee('Содержимое страницы «Report»');
});

it('creates a field that extends Text and has its own view', function (): void {
    $this->artisan('fastleo:make-field', ['name' => 'Phone'])->assertSuccessful();

    require $this->sandbox.'/Fastleo/Fields/Phone.php';
    $class = $this->namespace.'\\Fields\\Phone';
    $field = $class::make('phone', 'Телефон')->forModel(Article::class);

    expect($field)->toBeInstanceOf(Text::class)
        ->and($field->view())->toBe('fastleo.fields.phone');

    View::share('errors', new ViewErrorBag);
    $html = view($field->view(), ['field' => $field, 'value' => '+7', 'name' => 'phone', 'path' => 'phone', 'id' => 'f-phone'])->render();

    expect($html)->toContain('name="phone"', 'id="f-phone"');
});

it('does not overwrite files without --force', function (): void {
    $this->artisan('fastleo:make-action', ['name' => 'Same'])->assertSuccessful();
    $path = $this->sandbox.'/Fastleo/Actions/Same.php';
    File::put($path, 'custom');

    $this->artisan('fastleo:make-action', ['name' => 'Same'])->expectsOutputToContain('уже есть')->assertFailed();
    expect(File::get($path))->toBe('custom');

    $this->artisan('fastleo:make-action', ['name' => 'Same', '--force' => true])->assertSuccessful();
    expect(File::get($path))->toContain('final class Same extends Action');
});

it('does not overwrite an existing view either', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/views/fastleo/filters');
    File::put($this->sandbox.'/views/fastleo/filters/by-city.blade.php', 'mine');

    $this->artisan('fastleo:make-filter', ['name' => 'ByCity'])->assertFailed();

    expect($this->sandbox.'/Fastleo/Filters/ByCity.php')->not->toBeFile()
        ->and(File::get($this->sandbox.'/views/fastleo/filters/by-city.blade.php'))->toBe('mine');
});

it('rejects a bad class name and does not double the Page suffix', function (): void {
    $this->artisan('fastleo:make-widget', ['name' => '1bad name'])->expectsOutputToContain('не подходит')->assertFailed();

    $this->artisan('fastleo:make-page', ['name' => 'StatsPage'])->assertSuccessful();

    expect($this->sandbox.'/Fastleo/StatsPage.php')->toBeFile()
        ->and($this->sandbox.'/Fastleo/StatsPagePage.php')->not->toBeFile();
});

it('creates a settings page that the registry finds', function (): void {
    $this->artisan('fastleo:make-settings', ['name' => 'Contacts'])
        ->expectsOutputToContain('Класс создан:')
        ->assertSuccessful();

    $path = $this->sandbox.'/Fastleo/ContactsSettings.php';
    expect($path)->toBeFile();
    require_once $path;
    $class = $this->namespace.'\\ContactsSettings';

    expect(new $class)->toBeInstanceOf(Settings::class)
        ->and(array_keys(new $class()->schema()->fields()))->not->toBeEmpty();

    $this->artisan('fastleo:make-settings', ['name' => 'ContactsSettings'])->expectsOutputToContain('уже есть')->assertFailed();
    $this->artisan('fastleo:make-settings', ['name' => 'ContactsSettings', '--force' => true])->assertSuccessful();
    $this->artisan('fastleo:make-settings', ['name' => 'bad name!'])->expectsOutputToContain('не подходит')->assertFailed();
});

it('creates the dashboard class that the panel uses', function (): void {
    $this->artisan('fastleo:make-dashboard')->expectsOutputToContain('Класс создан:')->assertSuccessful();

    $path = $this->sandbox.'/Fastleo/Dashboard.php';
    expect($path)->toBeFile();
    require_once $path;
    $class = $this->namespace.'\\Dashboard';

    expect(Fastleo::dashboard())->toBeInstanceOf($class)
        ->and(Fastleo::dashboard())->toBeInstanceOf(Dashboard::class)
        ->and(Fastleo::dashboard()->widgets())->not->toBeEmpty();

    $this->artisan('fastleo:make-dashboard')->expectsOutputToContain('уже есть')->assertFailed();
});

describe('policy', function (): void {
    beforeEach(function (): void {
        // Namespace приложения определяется по путям composer.json — до подмены папки app.
        $this->appNamespace = $this->app->getNamespace();
        $this->app->useAppPath($this->sandbox.'/app');
    });

    it('creates a policy with every ability the admin asks for', function (): void {
        $this->artisan('fastleo:make-policy', ['name' => 'Article', '--model' => Article::class])
            ->expectsOutputToContain('Класс создан:')
            ->assertSuccessful();

        $path = $this->sandbox.'/app/Policies/ArticlePolicy.php';
        expect($path)->toBeFile();
        $code = File::get($path);
        $code = str_replace('namespace '.$this->appNamespace.'Policies;', 'namespace Generated\\Policies'.uniqid().';', $code);
        File::put($path, $code);
        require_once $path;
        $class = collect(get_declared_classes())->first(fn (string $class): bool => str_ends_with($class, '\\ArticlePolicy') && str_starts_with($class, 'Generated\\Policies'));

        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete', 'reorder', 'import', 'export'] as $ability) {
            expect(method_exists($class, $ability))->toBeTrue($ability);
        }

        $policy = new $class;
        $user = admin();
        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->update($user, Article::factory()->create()))->toBeTrue();
    });

    it('refuses an unknown model, a wrong name and an existing file', function (): void {
        $this->artisan('fastleo:make-policy', ['name' => 'Ghosts'])->expectsOutputToContain('не найдена')->assertFailed();
        $this->artisan('fastleo:make-policy', ['name' => 'bad name!'])->expectsOutputToContain('не подходит')->assertFailed();

        $this->artisan('fastleo:make-policy', ['name' => 'Article', '--model' => Article::class])->assertSuccessful();
        $this->artisan('fastleo:make-policy', ['name' => 'ArticlePolicy', '--model' => Article::class])->expectsOutputToContain('уже есть')->assertFailed();
        $this->artisan('fastleo:make-policy', ['name' => 'Article', '--model' => Article::class, '--force' => true])->assertSuccessful();
    });
});
