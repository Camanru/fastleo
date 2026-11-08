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
    /** Слова PHP, которые не могут быть именем класса (в нижнем регистре; регистр в PHP не важен). */
    private const array RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare',
        'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch',
        'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or',
        'print', 'private', 'protected', 'public', 'readonly', 'require', 'return', 'static', 'switch', 'throw', 'trait', 'try',
        'unset', 'use', 'var', 'while', 'xor', 'yield', 'self', 'parent', 'int', 'float', 'bool', 'string', 'true', 'false',
        'null', 'void', 'iterable', 'object', 'mixed', 'never',
    ];

    /**
     * @param  bool  $interactive  есть кому задавать вопросы (нет `--no-interaction`, запуск из терминала)
     */
    public function __construct(private Command $command, private Factory $components, private bool $interactive) {}

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

        if ($input === null) {
            $this->components->error(__('fastleo::console.make_name_required', ['example' => $this->command->getName().' Product']));

            return Command::FAILURE;
        }

        $name = Str::studly($suffix === '' ? $input : Str::chopEnd($input, $suffix));

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error(__('fastleo::console.make_name_invalid', ['name' => $name]));

            return Command::FAILURE;
        }

        // Без окончания имя — само имя класса, а слова языка (`Print`, `List`, `Match`) классом быть не могут: файл не разберётся.
        if ($suffix === '' && in_array(strtolower($name), self::RESERVED, true)) {
            $this->components->error(__('fastleo::console.make_name_reserved', ['name' => $name, 'example' => 'My'.$name]));

            return Command::FAILURE;
        }

        $class = $name.$suffix;

        // Имя класса, совпавшее с классом из `use` заготовки (`Action`, `Filter`, `Text`, `Model`), даёт «Cannot redeclare class».
        if (in_array(strtolower($class), $this->importedNames($stub), true)) {
            $this->components->error(__('fastleo::console.make_name_taken', ['name' => $class, 'example' => 'My'.$class]));

            return Command::FAILURE;
        }

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
     * Короткие имена классов, которые подключает заготовка (`use Fastleo\Actions\Action;` → `action`), строчными буквами.
     *
     * @return list<string>
     */
    private function importedNames(string $stub): array
    {
        preg_match_all('/^use [^;]*?(\w+);$/m', File::get($this->stubPath($stub)), $imports);

        return array_map(strtolower(...), $imports[1]);
    }

    /**
     * Путь к заготовке в пакете.
     */
    private function stubPath(string $stub, string $extension = '.stub'): string
    {
        return dirname(__DIR__, 2).'/stubs/'.$stub.$extension;
    }

    /**
     * Имя класса из аргумента команды или из вопроса; null — имени нет и спросить некому (`--no-interaction`).
     */
    private function askName(): ?string
    {
        $argument = $this->command->argument('name');

        if (is_string($argument) && trim($argument) !== '') {
            return trim($argument);
        }

        return $this->interactive ? text(label: __('fastleo::console.make_class_name'), required: true) : null;
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
        File::put($path, strtr(File::get($this->stubPath($stub, $extension)), $replace));
    }
}
