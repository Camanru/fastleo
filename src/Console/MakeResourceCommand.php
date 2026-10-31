<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\text;

/**
 * Создаёт класс ресурса в папке ресурсов сайта; с `--generate` собирает поля по колонкам таблицы модели.
 *
 * ```bash
 * php artisan fastleo:make-resource Doctor --generate
 * php artisan fastleo:make-resource Doctor --model="App\Models\Staff\Doctor" --force
 * ```
 */
#[AsCommand(name: 'fastleo:make-resource')]
#[Description('Создать раздел админки (ресурс) для модели; --generate — поля по колонкам таблицы')]
#[Signature('fastleo:make-resource
        {name? : Имя раздела латиницей: Doctor → DoctorResource}
        {--model= : Класс модели (по умолчанию App\Models\{name})}
        {--generate : Собрать поля по колонкам таблицы}
        {--force : Перезаписать существующий файл}')]
final class MakeResourceCommand extends Command
{
    /** Колонки ручного порядка: становятся `$sortColumn` ресурса. */
    private const array SORT_COLUMNS = ['sort', 'position', 'sort_order'];

    /** Колонки-картинки по имени (`photo`, `cover_image`). */
    private const array IMAGE_NAMES = ['image', 'photo', 'picture', 'avatar', 'logo', 'cover', 'thumbnail', 'poster', 'banner'];

    /** Колонки-файлы по имени (`price_file`, `attachment`). */
    private const array FILE_NAMES = ['file', 'document', 'attachment', 'pdf'];

    /** JSON-колонки галереи по имени (нужен каст `array`). */
    private const array GALLERY_NAMES = ['gallery', 'photos', 'images', 'pictures'];

    /** Текстовые колонки визуального редактора. */
    private const array RICH_TEXT_NAMES = ['content', 'body', 'html'];

    /** Имена колонок пар «название — значение» (json с кастом модели) — поле KeyValue. */
    private const array KEY_VALUE_NAMES = ['specs', 'attributes', 'properties', 'characteristics', 'params', 'parameters'];

    /** JSON-колонки для поисковиков по имени (`seo`, `page_seo`; нужен каст `array`) — поле Seo. */
    private const array SEO_NAMES = ['seo'];

    /** Колонки, которые форма не показывает: пароль, токены, флаг доступа в админку, 2FA. */
    private const array SKIPPED_COLUMNS = ['remember_token', 'password', 'fastleo_admin', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    /**
     * Свойства ресурса, найденные по таблице: `sortColumn`, `treeColumn`.
     *
     * @var array<string, string>
     */
    private array $properties = [];

    /**
     * Создать файл ресурса.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $name = Str::studly(Str::beforeLast(trim($this->stringArgument('name') ?? text(label: __('fastleo::console.make_name'), required: true)), 'Resource'));

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error(__('fastleo::console.make_name_invalid', ['name' => $name]));

            return self::FAILURE;
        }

        $model = ltrim($this->stringOption('model') ?? app()->getNamespace().'Models\\'.$name, '\\');

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            $this->components->error(__('fastleo::console.make_model_missing', ['model' => $model]));

            return self::FAILURE;
        }

        $class = $name.'Resource';
        $path = rtrim(config()->string('fastleo.resources.path'), '/').'/'.$class.'.php';

        if (File::exists($path) && ! $this->option('force')) {
            $this->components->error(__('fastleo::console.make_exists', ['path' => $path]));

            return self::FAILURE;
        }

        $fields = [];
        $this->properties = [];

        if ($this->option('generate')) {
            $fields = $this->generateFields(new $model);

            if ($fields === null) {
                return self::FAILURE;
            }
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->render($class, $model, $fields));
        Fastleo::resources()->flush();

        $this->components->info(__('fastleo::console.make_created', ['path' => $path]));
        $this->components->bulletList([__('fastleo::console.make_next', [
            'url' => route('fastleo.resources.index', Str::kebab(Str::pluralStudly(class_basename($model)))),
        ])]);

        return self::SUCCESS;
    }

    /**
     * Поля по колонкам таблицы модели: `[класс поля, строка кода]`. null — таблицы нет.
     *
     * @return list<array{string, string, string}>|null
     */
    private function generateFields(Model $model): ?array
    {
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();

        if (! $schema->hasTable($table)) {
            $this->components->error(__('fastleo::console.make_table_missing', ['table' => $table]));

            return null;
        }

        $skipped = [...self::SKIPPED_COLUMNS, $model->getKeyName(), $model->getCreatedAtColumn(), $model->getUpdatedAtColumn()];

        if (method_exists($model, 'getDeletedAtColumn')) {
            $skipped[] = $model->getDeletedAtColumn();
        }

        $fields = [];
        $searchable = false;

        foreach ($schema->getColumns($table) as $column) {
            if (in_array($column['name'], $skipped, true)) {
                continue;
            }

            // Колонка ручного порядка — свойство ресурса, а не поле формы (её пишет пакет).
            if (! isset($this->properties['sortColumn']) && in_array($column['name'], self::SORT_COLUMNS, true) && $this->isIntegerColumn($column)) {
                $this->properties['sortColumn'] = $column['name'];

                continue;
            }

            // Отметка «просмотрено» — тоже свойство ресурса: её ставит пакет, когда запись открывают.
            if ($column['name'] === 'read_at' && $column['nullable'] && in_array(strtolower($column['type_name']), ['datetime', 'timestamp', 'timestamptz'], true)) {
                $this->properties['readColumn'] = 'read_at';

                continue;
            }

            if ($column['name'] === 'parent_id' && ($parentRelation = $this->belongsToRelation($model, 'parent_id')) instanceof BelongsTo && $parentRelation->getRelated()::class === $model::class) {
                $this->properties['treeColumn'] = 'parent_id';
            }

            if (! $model->isFillable($column['name'])) {
                $this->components->warn(__('fastleo::console.make_skipped_guarded', ['column' => $column['name']]));

                continue;
            }

            $field = $this->fieldFor($column, ! $searchable, $model);

            if ($field === null) {
                $this->components->warn(__('fastleo::console.make_skipped_type', ['column' => $column['name'], 'type' => $column['type']]));

                continue;
            }

            $searchable = $searchable || $field[0] === 'Text';
            $fields[] = $field;
        }

        // Slug строится из title/name, иначе из первого текстового поля.
        $texts = array_values(array_filter(array_map(fn (array $field): ?string => $field[0] === 'Text' ? $field[2] : null, $fields)));
        $source = array_values(array_intersect(['title', 'name'], $texts))[0] ?? $texts[0] ?? null;

        $fields = array_map(fn (array $field): array => [$field[0], str_replace('{{ from }}', $source === null ? '' : '->from('.var_export($source, true).')', $field[1]), $field[2]], $fields);

        $this->components->info(__('fastleo::console.make_generated', ['table' => $table, 'count' => count($fields)]));

        return $fields;
    }

    /**
     * Поле для колонки по её типу и имени: `[класс поля, строка кода]` или null, если тип пока не поддержан.
     * Первое текстовое поле становится полем поиска и сортировки.
     *
     * @param  array{name: string, type_name: string, type: string, nullable: bool, default: mixed, comment?: string|null}  $column
     * @return array{string, string, string}|null
     */
    private function fieldFor(array $column, bool $firstText, Model $model): ?array
    {
        $name = $column['name'];
        $typeName = strtolower($column['type_name']);

        // SQLite хранит json как text: колонка с кастом массива модели — json.
        if ($model->hasCast($name, ['array', 'json', 'object', 'collection', 'json:unicode', 'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection'])) {
            $typeName = 'json';
        }
        $type = strtolower($column['type']);
        $relation = str_ends_with($name, '_id') ? $this->belongsToRelation($model, $name) : null;

        // Переводимый атрибут модели spatie/laravel-translatable — многоязычный текст.
        $translatable = method_exists($model, 'isTranslatableAttribute') && $model->isTranslatableAttribute($name);

        $class = match (true) {
            $relation instanceof BelongsTo => 'BelongsTo',
            $translatable => in_array($name, self::RICH_TEXT_NAMES, true) ? 'RichText' : 'Text',
            $name === 'slug' && ! in_array($typeName, ['json', 'jsonb'], true) => 'Slug',
            in_array($typeName, ['bool', 'boolean'], true) || $type === 'tinyint(1)' => 'Toggle',
            // SQLite хранит json как text — галерею узнаём по имени и касту модели.
            in_array($typeName, ['json', 'jsonb', 'text', 'longtext'], true) && $this->matchesName($name, self::GALLERY_NAMES) && $model->hasCast($name) => 'Gallery',
            in_array($typeName, ['json', 'jsonb', 'text', 'longtext'], true) && $this->matchesName($name, self::KEY_VALUE_NAMES) && $model->hasCast($name) => 'KeyValue',
            in_array($typeName, ['json', 'jsonb', 'text', 'longtext'], true) && $this->matchesName($name, self::SEO_NAMES) && $model->hasCast($name) => 'Seo',
            in_array($typeName, ['json', 'jsonb'], true) => null,
            in_array($typeName, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'], true) => 'Number',
            in_array($typeName, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8'], true) => 'Number',
            $typeName === 'date' => 'Date',
            in_array($typeName, ['datetime', 'timestamp', 'timestamptz'], true) => 'DateTime',
            in_array($typeName, ['time', 'timetz'], true) => 'Time',
            in_array($typeName, ['text', 'tinytext', 'mediumtext', 'longtext'], true) => in_array($name, self::RICH_TEXT_NAMES, true) ? 'RichText' : 'Textarea',
            $this->matchesName($name, self::IMAGE_NAMES) => 'Image',
            $this->matchesName($name, self::FILE_NAMES) => 'File',
            str_contains($name, 'email') => 'Email',
            str_contains($name, 'url') || str_contains($name, 'website') || str_contains($name, 'link') => 'Url',
            str_contains($name, 'phone') || $name === 'tel' => 'Tel',
            str_contains($name, 'color') => 'Color',
            default => 'Text',
        };

        if ($class === null) {
            return null;
        }

        $label = $class === 'Seo' && $name === 'seo' ? 'SEO' : null;
        $label ??= isset($column['comment']) && $column['comment'] !== '' ? $column['comment'] : Str::ucfirst(str_replace('_', ' ', $name === 'parent_id' ? 'parent' : Str::beforeLast($name, '_id')));
        $code = $class.'::make('.var_export($name, true).', '.var_export($label, true).')';

        if ($class === 'BelongsTo') {
            $default = Str::camel(Str::beforeLast($name, '_id'));
            $method = $this->relationMethod($model, $name);

            return [$class, $code.($method !== $default ? '->relation('.var_export($method, true).')' : '').(! $column['nullable'] && $column['default'] === null ? '->required()' : ''), $name];
        }

        if ($class === 'Slug') {
            return [$class, $code.'{{ from }}', $name];
        }

        if ($translatable) {
            $code .= '->translatable()';
        }

        if ($class === 'Number' && $this->isIntegerColumn($column)) {
            $code .= '->integer()';
        }

        $defaultLength = $class === 'Tel' ? 32 : 255;

        if (! $translatable && in_array($class, ['Text', 'Email', 'Url', 'Tel'], true) && preg_match('/\((\d+)\)/', $type, $length) === 1 && (int) $length[1] !== $defaultLength) {
            $code .= '->maxLength('.$length[1].')';
        }

        if (! in_array($class, ['Toggle', 'Gallery', 'KeyValue', 'Seo'], true) && ! $column['nullable'] && $column['default'] === null) {
            $code .= '->required()';
        }

        if ($class === 'Gallery') {
            $code .= '->hideInList()';
        }

        if ($class === 'Text' && $firstText) {
            $code .= '->searchable()->sortable()';
        }

        return [$class, $code, $name];
    }

    /**
     * Имя колонки — одно из слов или кончается на `_слово`: `photo`, `doctor_photo`.
     *
     * @param  list<string>  $words
     */
    private function matchesName(string $name, array $words): bool
    {
        return array_any($words, fn (string $word): bool => $name === $word || str_ends_with($name, '_'.$word));
    }

    /**
     * Колонка целочисленная.
     *
     * @param  array{type_name: string}  $column
     */
    private function isIntegerColumn(array $column): bool
    {
        return in_array(strtolower($column['type_name']), ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'], true);
    }

    /**
     * Связь BelongsTo модели по колонке внешнего ключа (`category_id` → `category()`) или null.
     *
     * @return BelongsTo<Model, Model>|null
     */
    private function belongsToRelation(Model $model, string $column): ?BelongsTo
    {
        $method = $this->relationMethod($model, $column);

        try {
            $reflection = $method === null ? null : new ReflectionMethod($model, $method);
            // Только свои методы модели без параметров: не вызывать push(), truncate() и прочее из Eloquent.
            // Метод трейта Laravel (SoftDeletes::restore) объявлен «в модели», поэтому проверяется и файл.
            $file = $reflection?->getFileName();
            $own = $reflection instanceof ReflectionMethod && ! str_starts_with($reflection->getDeclaringClass()->getName(), 'Illuminate\\')
                && (! is_string($file) || ! str_contains(str_replace('\\', '/', $file), '/Illuminate/'))
                && $reflection->getNumberOfRequiredParameters() === 0;
            $relation = $own ? $model->{$method}() : null;
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof BelongsTo && $relation->getForeignKeyName() === $column ? $relation : null;
    }

    /**
     * Метод связи для колонки: имя без `_id` (`category_id` → `category`, `parent_id` → `parent`).
     */
    private function relationMethod(Model $model, string $column): ?string
    {
        $method = Str::camel(Str::beforeLast($column, '_id'));

        return method_exists($model, $method) ? $method : null;
    }

    /**
     * Текст класса ресурса по заготовке stubs/resource.stub.
     *
     * @param  class-string<Model>  $model
     * @param  list<array{string, string, string}>  $fields
     */
    private function render(string $class, string $model, array $fields): string
    {
        $imports = array_unique(array_map(fn (array $field): string => 'Fastleo\\Fields\\'.$field[0], $fields));
        $imports = [...$imports, \Fastleo\Resource::class];
        $basename = class_basename($model);
        $modelName = $basename;

        // Модель с именем, как у класса пакета (`Resource`, `File`, `Image`), импортируется под другим именем.
        if (in_array($basename, array_map(class_basename(...), $imports), true)) {
            $modelName = $basename.'Model';
            $imports[] = $model.' as '.$modelName;
        } else {
            $imports[] = $model;
        }

        sort($imports);

        $stub = (string) file_get_contents(dirname(__DIR__, 2).'/stubs/resource.stub');
        $properties = '';

        foreach ($this->properties as $property => $column) {
            $properties .= "\n\n    protected static ?string \$".$property.' = '.var_export($column, true).';';
        }

        if ($fields === []) {
            $stub = str_replace("[\n{{ fields }}\n        ]", '[]', $stub);
        }

        return str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ modelBasename }}', '{{ label }}', '{{ singular }}', '{{ properties }}', '{{ fields }}'],
            [
                trim(config()->string('fastleo.resources.namespace'), '\\'),
                implode("\n", array_map(fn (string $import): string => 'use '.$import.';', $imports)),
                $class,
                $modelName,
                Str::headline(Str::pluralStudly($basename)),
                Str::headline($basename),
                $properties,
                implode("\n", array_map(fn (array $field): string => '            '.$field[1].',', $fields)),
            ],
            $stub,
        );
    }

    /**
     * Строковый аргумент или null.
     */
    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Строковая опция или null.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
