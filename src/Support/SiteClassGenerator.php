<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Fastleo;
use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Общая часть команд `fastleo:make-action|filter|widget|page|field|settings|dashboard`: создаёт класс в папке разделов сайта
 * (`fastleo.resources.path`) по заготовке из `stubs/` и, если нужно, Blade-шаблон в `resources/views`.
 */
final readonly class SiteClassGenerator
{
    public function __construct(private Command $command, private Factory $components) {}

    /**
     * Создать класс (и шаблон) и подсказать, куда его подключить.
     *
     * @param  string  $directory  подпапка внутри папки разделов (`Actions`) или пустая строка — прямо в ней
     * @param  string  $stub  имя заготовки класса в `stubs/` без расширения
     * @param  string  $suffix  обязательное окончание имени класса (`Page`) или пустая строка
     * @param  string|null  $view  имя шаблона сайта (`fastleo.widgets.queue-size`) или null — шаблона нет
     * @param  string  $nextKey  ключ подсказки «куда подключить» в `fastleo::console`
     * @param  string  $name  имя класса, когда оно фиксировано (`Dashboard`), иначе — из аргумента команды или вопроса
     */
    public function generate(string $directory, string $stub, string $suffix, ?string $view, string $nextKey, string $name = ''): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $input = $name !== '' ? $name : $this->askName();
        $name = Str::studly($suffix === '' ? $input : Str::beforeLast($input, $suffix));

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error(__('fastleo::console.make_name_invalid', ['name' => $name]));

            return Command::FAILURE;
        }

        $class = $name.$suffix;
        $classPath = rtrim(config()->string('fastleo.resources.path'), '/').($directory === '' ? '' : '/'.$directory).'/'.$class.'.php';
        $viewName = $view === null ? null : str_replace('{kebab}', Str::kebab($name), $view);
        $viewPath = $viewName === null ? null : $this->viewsPath().'/'.str_replace('.', '/', $viewName).'.blade.php';

        foreach (array_filter([$classPath, $viewPath]) as $path) {
            if (File::exists($path) && $this->command->option('force') !== true) {
                $this->components->error(__('fastleo::console.make_exists', ['path' => $path]));

                return Command::FAILURE;
            }
        }

        $replace = [
            '{{ namespace }}' => trim(config()->string('fastleo.resources.namespace'), '\\').($directory === '' ? '' : '\\'.$directory),
            '{{ class }}' => $class,
            '{{ label }}' => Str::headline($name),
            '{{ view }}' => $viewName ?? '',
        ];

        $this->write($classPath, $stub, $replace);
        $this->components->info(__('fastleo::console.make_class_created', ['path' => $classPath]));

        if ($viewPath !== null) {
            $this->write($viewPath, 'views/'.$stub, $replace, '.blade.stub');
            $this->components->info(__('fastleo::console.make_view_created', ['path' => $viewPath]));
        }

        Fastleo::resources()->flush();
        $this->components->bulletList([__('fastleo::console.'.$nextKey, ['class' => $class])]);

        return Command::SUCCESS;
    }

    /**
     * Имя класса из аргумента команды или из вопроса.
     */
    private function askName(): string
    {
        $argument = $this->command->argument('name');

        return is_string($argument) && trim($argument) !== '' ? trim($argument) : text(label: __('fastleo::console.make_class_name'), required: true);
    }

    /**
     * Первая папка шаблонов сайта (`view.paths`, обычно resources/views).
     */
    private function viewsPath(): string
    {
        $paths = config('view.paths');

        return rtrim(is_array($paths) && is_string($paths[0] ?? null) ? $paths[0] : resource_path('views'), '/');
    }

    /**
     * Записать файл по заготовке.
     *
     * @param  array<string, string>  $replace
     */
    private function write(string $path, string $stub, array $replace, string $extension = '.stub'): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, strtr(File::get(dirname(__DIR__, 2).'/stubs/'.$stub.$extension), $replace));
    }
}
