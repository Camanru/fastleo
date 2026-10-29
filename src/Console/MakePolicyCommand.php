<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Support\UserModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

/**
 * Создать Policy модели со всеми способностями, которые спрашивает админка
 *
 * ```bash
 * php artisan fastleo:make-policy Doctor
 * ```
 */
#[AsCommand(name: 'fastleo:make-policy')]
#[Description('Создать Policy модели (app/Policies) со всеми способностями, которые спрашивает админка')]
#[Signature('fastleo:make-policy {name? : имя модели, например Doctor} {--model= : класс модели (по умолчанию App\\Models\\{name})} {--force : перезаписать существующий файл}')]
final class MakePolicyCommand extends Command
{
    /**
     * Создать `app/Policies/{Name}Policy.php`: Laravel находит такой класс для `App\Models\{Name}` сам.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $argument = $this->argument('name');
        $input = is_string($argument) && trim($argument) !== '' ? trim($argument) : text(label: __('fastleo::console.make_model_name'), required: true);
        $name = Str::studly(Str::beforeLast($input, 'Policy'));

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error(__('fastleo::console.make_name_invalid', ['name' => $name]));

            return self::FAILURE;
        }

        $option = $this->option('model');
        $model = ltrim(is_string($option) && $option !== '' ? $option : app()->getNamespace().'Models\\'.$name, '\\');

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            $this->components->error(__('fastleo::console.make_model_missing', ['model' => $model]));

            return self::FAILURE;
        }

        $class = class_basename($model).'Policy';
        $path = app_path('Policies/'.$class.'.php');

        if (File::exists($path) && $this->option('force') !== true) {
            $this->components->error(__('fastleo::console.make_exists', ['path' => $path]));

            return self::FAILURE;
        }

        $user = UserModel::fastleoClassName() ?? Authenticatable::class;
        $sameName = class_basename($user) === class_basename($model) && $user !== $model;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, strtr(File::get(dirname(__DIR__, 2).'/stubs/policy.stub'), [
            '{{ namespace }}' => app()->getNamespace().'Policies',
            '{{ class }}' => $class,
            '{{ model }}' => $model,
            '{{ modelType }}' => class_basename($model),
            '{{ label }}' => Str::headline(class_basename($model)),
            '{{ userType }}' => $sameName ? '\\'.$user : class_basename($user),
            '{{ userImport }}' => $sameName || $user === $model ? '' : 'use '.$user.";\n",
        ]));

        $this->components->info(__('fastleo::console.make_class_created', ['path' => $path]));
        $this->components->bulletList([__('fastleo::console.make_next_policy', ['class' => $class])]);

        return self::SUCCESS;
    }
}
