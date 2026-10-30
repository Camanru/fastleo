<?php

declare(strict_types=1);

namespace Fastleo\Support;

use BackedEnum;
use Fastleo\Actions\SetToggle;
use Fastleo\Fastleo;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\BelongsToMany;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Field;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\HasMany;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Number;
use Fastleo\Fields\Password;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Select;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Imports\ImportRecords;
use Fastleo\Messengers\ChatIds;
use Fastleo\Resource;
use Fastleo\Settings;
use Fastleo\SoftNetica\Connection;
use Fastleo\SoftNetica\SyncState;
use Fastleo\Support\Html\HtmlLoss;
use Fastleo\Support\Html\RichTextSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Проверка сайта и описанных разделов: находит то, что сломается при работе админки, до первого открытия страницы.
 * Источник для `fastleo:doctor`. Ошибка — админка или раздел не заработают, предупреждение — работает, но не так, как задумано.
 *
 * ```php
 * foreach ((new Doctor)->run() as $problem) {
 *     echo $problem['level'].' '.$problem['scope'].': '.$problem['message'];
 * }
 * ```
 *
 * @phpstan-type Problem array{level: 'error'|'warning', scope: string, message: string, hint: string|null}
 */
final class Doctor
{
    /** Таблицы, которые создаёт пакет. */
    private const array TABLES = ['fastleo_imports', 'fastleo_logins', 'fastleo_two_factor', 'fastleo_activity', 'fastleo_settings', 'fastleo_notifications', 'fastleo_notification_bots', 'fastleo_channels', 'fastleo_publications', 'fastleo_publication_deliveries', 'fastleo_dashboard_layouts'];

    /** Сколько записей раздела проверять на потерю разметки в RichText. */
    private const int RICH_TEXT_ROWS = 500;

    /** Типы, у которых значение не может отсутствовать при NOT NULL (пустое значение хранится как `false` или `[]`). */
    private const array NEVER_EMPTY = [Checkbox::class, Gallery::class, KeyValue::class, Seo::class];

    /** Касты JSON-значений, которые подходят полям с массивом. */
    private const array ARRAY_CASTS = ['array', 'json', 'json:unicode', 'collection', 'object'];

    /** @var list<Problem> */
    private array $problems = [];

    /**
     * Выполнить все проверки.
     *
     * @return list<Problem>
     */
    public function run(): array
    {
        $this->problems = [];

        $this->site();
        $this->cache();

        try {
            $registry = Fastleo::resources();
            $resources = $registry->all();
            $settings = $registry->settings();
            $registry->pages();
        } catch (Throwable $e) {
            $this->add('error', 'registry', 'registry', ['message' => $e->getMessage()]);

            return $this->problems;
        }

        foreach ($registry->builtIn() as $class) {
            if (! in_array($class, $resources, true)) {
                $this->add('warning', 'site', 'built_in_slug_taken', ['slug' => $class::slug(), 'other' => $resources[$class::slug()] ?? '?'], 'built_in_slug_taken_hint');
            }
        }

        $importable = false;

        foreach (array_keys($resources) as $slug) {
            $resource = $registry->find($slug);

            if ($resource instanceof \Fastleo\Resource) {
                $this->resource($resource);
                $importable = $importable || $resource::isImportable();
            }
        }

        if ($importable) {
            $this->importTimeout();
        }

        foreach (array_keys($settings) as $slug) {
            $page = $registry->findSettings($slug);

            if ($page instanceof Settings) {
                $this->settingsPage($page);
            }
        }

        return $this->problems;
    }

    /**
     * Импорт из файла идёт задачей до часа; если `retry_after` очереди не больше, очередь выдаст ту же задачу второму
     * обработчику, пока первая работает, и импорт упадёт. Проверяется только при очереди с `retry_after` (database, redis, beanstalkd).
     */
    private function importTimeout(): void
    {
        $connection = config('queue.default');
        $retryAfter = is_string($connection) ? config('queue.connections.'.$connection.'.retry_after') : null;
        $timeout = new ReflectionClass(ImportRecords::class)->getAttributes(Timeout::class)[0]->newInstance()->timeout;

        if (is_numeric($retryAfter) && (int) $retryAfter <= $timeout) {
            $this->add('warning', 'site', 'queue_retry_after', ['retry_after' => (string) (int) $retryAfter, 'timeout' => (string) $timeout], 'queue_retry_after_hint');
        }
    }

    /**
     * Окружение: guard, модель пользователя, таблицы, ассеты, очередь, ссылка на хранилище.
     */
    private function site(): void
    {
        $driver = config('auth.guards.'.Fastleo::GUARD.'.driver');

        if ($driver !== 'session') {
            $this->add('error', 'site', 'guard_driver', ['driver' => is_string($driver) ? $driver : 'не задан'], 'guard_driver_hint');
        }

        if (UserModel::fastleoClassName() === null) {
            $this->add('error', 'site', 'user_model', hint: 'user_model_hint');
        }

        $this->tables();
        $this->assets();
        $this->softNetica();
        $this->richTextSettings();

        if (config('queue.default') === 'sync') {
            $this->add('warning', 'site', 'queue_sync', hint: 'queue_sync_hint');
        }

        if (app()->isProduction() && SchedulerPulse::isStale()) {
            $this->add('warning', 'site', 'scheduler', ['minutes' => (string) intdiv(SchedulerPulse::STALE_SECONDS, 60)], 'scheduler_hint');
        }

        if (config()->boolean('fastleo.robots.enabled', false) && file_exists(public_path('robots.txt'))) {
            $this->add('warning', 'site', 'robots_file', hint: 'robots_file_hint');
        }

        $disk = config('fastleo.files.disk', 'public');

        if ($disk === 'public' && config('filesystems.disks.public.driver') === 'local' && ! file_exists(public_path('storage'))) {
            $this->add('warning', 'site', 'storage_link', hint: 'storage_link_hint');
        }
    }

    /**
     * Настройка `fastleo.rich_text` допустима.
     */
    private function richTextSettings(): void
    {
        try {
            RichTextSettings::validate();
        } catch (LogicException $e) {
            $this->add('error', 'site', 'rich_text_config', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Таблицы пакета и колонка доступа в таблице пользователей.
     */
    private function tables(): void
    {
        try {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    $this->add('error', 'site', 'table_missing_package', ['table' => $table], 'migrate_hint');
                }
            }

            $users = UserModel::fastleoClassName();

            if ($users !== null) {
                $table = new $users()->getTable();

                if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'fastleo_admin')) {
                    $this->add('error', 'site', 'column_missing_package', ['table' => $table, 'column' => 'fastleo_admin'], 'migrate_hint');
                }
            }
        } catch (Throwable $e) {
            $this->add('error', 'site', 'database', ['error' => $e->getMessage()], 'database_hint');
        }
    }

    /**
     * Подключение к SoftNetica: допустимый адрес, хосты кабинета, свежесть отчётов (когда токен введён).
     */
    private function softNetica(): void
    {
        if (! Connection::enabled()) {
            return;
        }

        try {
            if (Connection::token() === '') {
                return;
            }

            if (Connection::endpoint() === null) {
                $this->add('error', 'site', 'softnetica_url', hint: 'softnetica_url_hint');
            }

            if (Connection::cabinetHosts() === []) {
                $this->add('warning', 'site', 'softnetica_hosts');
            }

            $state = resolve(SyncState::class);

            if (Connection::connected() && $state->isStale()) {
                $this->add('warning', 'site', 'softnetica_stale', ['minutes' => SyncState::STALE_MINUTES], 'softnetica_stale_hint');
            }
        } catch (Throwable) {
            // Таблицы настроек нет — об этом уже сказано в проверке таблиц.
        }
    }

    /**
     * Опубликованные ассеты: есть ли и совпадают ли с версией пакета.
     */
    private function assets(): void
    {
        $missing = [];
        $outdated = [];

        foreach (glob(Assets::distPath('*')) ?: [] as $source) {
            $file = basename($source);
            $published = public_path(Assets::PUBLIC_PATH.'/'.$file);

            if (! is_file($published)) {
                $missing[] = $file;
            } elseif (md5_file($published) !== md5_file($source)) {
                $outdated[] = $file;
            }
        }

        if ($missing !== []) {
            $this->add('error', 'site', 'assets_missing', ['files' => implode(', ', $missing)], 'assets_hint');
        }

        if ($outdated !== []) {
            $this->add('warning', 'site', 'assets_outdated', ['files' => implode(', ', $outdated)], 'assets_hint');
        }
    }

    /**
     * Кеш списка разделов не отстаёт от классов в папке.
     */
    private function cache(): void
    {
        $registry = Fastleo::resources();
        $path = $registry->cachePath();

        if (! is_file($path)) {
            return;
        }

        $loaded = require $path;
        $cached = is_array($loaded) ? array_values(array_diff(array_filter($loaded, is_string(...)), ResourceRegistry::RUNTIME)) : [];
        $actual = $registry->discover();

        if (array_diff($cached, $actual) !== [] || array_diff($actual, $cached) !== []) {
            $this->add('warning', 'site', 'cache_stale', hint: 'cache_stale_hint');
        }
    }

    /**
     * Раздел: сборка описания, таблица и колонки, поля, фильтры и действия.
     */
    private function resource(Resource $resource): void
    {
        $scope = $resource::slug();

        try {
            $fields = $resource->schema()->fields();
            $resource->listFilters();
            $resource->listActions();
            $model = $resource->newModel();
        } catch (Throwable $e) {
            $this->add('error', $scope, 'resource', ['message' => $e->getMessage()]);

            return;
        }

        $columns = $this->columns($model, $scope);

        if ($columns === null) {
            return;
        }

        foreach (['sort_column' => $resource::sortColumn(), 'tree_column' => $resource::treeColumn()] as $key => $column) {
            if ($column !== null && ! isset($columns[$column])) {
                $this->add('error', $scope, $key, ['column' => $column, 'table' => $model->getTable()]);
            }
        }

        $this->setToggles($resource, $scope);
        $this->fields($fields, $model, $columns, $scope, ! $resource::isReadonly());
        $this->inputVars($fields, $scope, ! $resource::isReadonly());
    }

    /**
     * Готовые действия «Показать» / «Скрыть» (`SetToggle`) должны указывать на поле-галочку ресурса: иначе запуск упадёт.
     */
    private function setToggles(Resource $resource, string $scope): void
    {
        foreach ($resource->listActions() as $action) {
            if (! $action instanceof SetToggle) {
                continue;
            }

            $field = $resource->schema()->field($action->field());

            if (! $field instanceof Checkbox) {
                $this->add('error', $scope, 'set_toggle_field', ['field' => $action->field(), 'action' => $action->key()], 'set_toggle_field_hint');
            } elseif (! $field->isWritable()) {
                $this->add('warning', $scope, 'set_toggle_readonly', ['field' => $field->name, 'action' => $action->key()], 'set_toggle_readonly_hint');
            }
        }
    }

    /**
     * Сколько полей формы раздел может отправить самое большее, против `max_input_vars` PHP (по умолчанию 1000):
     * лишнее PHP молча отбрасывает, а форма откажет в сохранении.
     *
     * @param  list<Field>  $fields
     */
    private function inputVars(array $fields, string $scope, bool $editable): void
    {
        $limit = (int) ini_get('max_input_vars');

        if (! $editable || $limit <= 0) {
            return;
        }

        try {
            // Служебные поля формы: `_token`, `_method`, `_after`, метки `_fl_begin` и `_fl_end`.
            $count = 5 + $this->inputsOfAll($fields);
        } catch (Throwable) {
            return;
        }

        if ($count > $limit) {
            $this->add('warning', $scope, 'input_vars', ['count' => $count, 'limit' => $limit], 'input_vars_hint');
        }
    }

    /**
     * Сумма по полям.
     *
     * @param  list<Field>  $fields
     */
    private function inputsOfAll(array $fields): int
    {
        return array_sum(array_map($this->inputsOf(...), $fields));
    }

    /**
     * Наибольшее число полей формы, которые отправит это поле.
     */
    private function inputsOf(Field $field): int
    {
        return match (true) {
            $field instanceof HasMany => ($field->getMax() ?? 200) * ($this->inputsOfAll($field->schema()->fields()) + 2),
            $field instanceof Nested => $this->inputsOfAll($field->schema()->fields()) + 2,
            $field instanceof Repeater => $field->getMax() * ($this->inputsOfAll($field->schema()->fields()) + 1),
            $field instanceof Blocks => $field->getMax() * (max([0, ...array_map(fn (string $type): int => $this->inputsOfAll($field->typeFields($type)), array_keys($field->getTypes()))]) + 2),
            $field instanceof Gallery => $field->getMax() ?? 100,
            $field instanceof Tags => Tags::MAX_TAGS,
            $field instanceof KeyValue => $field->getMax() * 2,
            $field instanceof BelongsToMany => max(1, $field->relatedModel()->newQuery()->count()),
            $field instanceof Select && $field->isMultiple() => max(1, count($field->getOptions())),
            default => 1,
        };
    }

    /**
     * Страница настроек: поля собираются без ошибок.
     */
    private function settingsPage(Settings $settings): void
    {
        try {
            $settings->schema();
        } catch (Throwable $e) {
            $this->add('error', $settings::slug(), 'resource', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Колонки таблицы модели по именам или null, если таблицы нет (ошибка уже записана).
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function columns(Model $model, string $scope): ?array
    {
        try {
            $schema = Schema::connection($model->getConnectionName());

            if (! $schema->hasTable($model->getTable())) {
                $this->add('error', $scope, 'table_missing', ['table' => $model->getTable(), 'model' => $model::class], 'migrate_hint');

                return null;
            }

            $columns = [];

            foreach ($schema->getColumns($model->getTable()) as $column) {
                $columns[(string) $column['name']] = $column;
            }

            return $columns;
        } catch (Throwable $e) {
            $this->add('error', $scope, 'database', ['error' => $e->getMessage()], 'database_hint');

            return null;
        }
    }

    /**
     * Проверки полей: колонка, `$fillable`, связь, каст, NOT NULL, длина.
     *
     * @param  list<Field>  $fields
     * @param  array<string, array<string, mixed>>  $columns
     * @param  bool  $editable  раздел не только для чтения: значения полей записываются
     */
    private function fields(array $fields, Model $model, array $columns, string $scope, bool $editable): void
    {
        foreach ($fields as $field) {
            if ($field instanceof BelongsTo || $field instanceof BelongsToMany || $field instanceof Nested) {
                $this->relation($field, $scope);
            }

            if ($field instanceof Nested) {
                $this->nested($field, $scope, $editable);
            }

            if ($field instanceof Checkbox && $field->isQuickToggle() && (! $field->isWritable() || ! $field->showsInEditForm())) {
                $this->add('warning', $scope, 'quick_toggle_readonly', ['field' => $field->name], 'quick_toggle_readonly_hint');
            }

            if (! $field->writesAttribute()) {
                continue;
            }

            $column = $columns[$field->name] ?? null;

            if ($column === null && ! $this->hasMutator($model, $field)) {
                $this->add('error', $scope, 'field_no_column', ['field' => $field->name, 'table' => $model->getTable()], 'field_no_column_hint');

                continue;
            }

            if ($editable && $field->isWritable() && ! $model->isFillable($field->name)) {
                $this->add('error', $scope, 'field_not_fillable', ['field' => $field->name, 'model' => $model::class], 'field_not_fillable_hint');
            }

            $this->casts($field, $model, $scope);

            if ($field instanceof RichText && $editable && $field->isWritable()) {
                $this->richTextLoss($field, $model, $scope);
            }

            if ($column !== null) {
                $this->column($field, $model, $column, $scope, $editable);
            }
        }
    }

    /**
     * Старые записи теряют разметку, когда редактор сохранит их: чистка HTML вырежет теги и атрибуты, которые сайт не разрешил.
     * Проверяются первые `RICH_TEXT_ROWS` записей; поле с `sanitize()` сайта проверяется по его правилам.
     */
    private function richTextLoss(RichText $field, Model $model, string $scope): void
    {
        try {
            $values = $model->newQueryWithoutScopes()->toBase()->whereNotNull($field->name)->limit(self::RICH_TEXT_ROWS)->pluck($field->name);
        } catch (Throwable) {
            return;
        }

        $affected = 0;
        $lost = ['tags' => [], 'attributes' => [], 'hosts' => []];

        foreach ($values as $value) {
            $texts = [$value];

            if ($field->isTranslatable() && is_string($value)) {
                $decoded = json_decode($value, true);
                $texts = is_array($decoded) ? $decoded : $texts;
            }

            $found = false;

            foreach ($texts as $text) {
                if (! is_string($text) || trim($text) === '') {
                    continue;
                }

                try {
                    $loss = HtmlLoss::between($text, $field->sanitize($text));
                } catch (LogicException) {
                    return; // неверную настройку fastleo.rich_text doctor уже показал ошибкой
                }

                foreach ($loss as $kind => $names) {
                    $lost[$kind] = array_values(array_unique([...$lost[$kind], ...$names]));
                    $found = $found || $names !== [];
                }
            }

            $affected += $found ? 1 : 0;
        }

        if ($affected === 0) {
            return;
        }

        $parts = [];

        foreach (['tags', 'attributes', 'hosts'] as $kind) {
            if ($lost[$kind] !== []) {
                $parts[] = trans()->string('fastleo::doctor.rich_text_'.$kind, ['list' => implode(', ', array_slice($lost[$kind], 0, 12))], Fastleo::LOCALE);
            }
        }

        // Поле со своим sanitize() чистит по своим правилам: совет про fastleo.rich_text его не касается.
        $sanitizer = new ReflectionMethod($field, 'sanitize')->getDeclaringClass()->getName();
        $hint = $sanitizer === RichText::class ? 'rich_text_loss_hint' : 'rich_text_loss_custom_hint';

        $this->add('warning', $scope, 'rich_text_loss', ['field' => $field->name, 'count' => $affected, 'checked' => count($values), 'lost' => implode('; ', $parts), 'class' => $sanitizer], $hint);
    }

    /**
     * Метод связи существует и подходит полю.
     */
    private function relation(Field $field, string $scope): void
    {
        try {
            if ($field instanceof BelongsTo || $field instanceof BelongsToMany || $field instanceof Nested) {
                $field->relationInstance();
            }
        } catch (Throwable $e) {
            $this->add('error', $scope, 'relation', ['field' => $field->name, 'message' => $e->getMessage()]);

            return;
        }

        if ($field instanceof BelongsTo || $field instanceof BelongsToMany) {
            $this->remoteOptions($field, $scope);
        }
    }

    /**
     * Связанных записей больше `fastleo.relations.preload`: вместо списка вариантов форма и фильтр ищут по серверу.
     */
    private function remoteOptions(BelongsTo|BelongsToMany $field, string $scope): void
    {
        try {
            $remote = $field->isRemote();
        } catch (Throwable) {
            return; // нет таблицы или связь не собирается — это уже показано ошибкой
        }

        if ($remote) {
            $this->add('warning', $scope, 'relation_remote', ['field' => $field->name, 'limit' => (string) config()->integer('fastleo.relations.preload', 100)], 'relation_remote_hint');
        }
    }

    /**
     * Поля блоков HasMany/HasOne проверяются по таблице связанной модели.
     */
    private function nested(Nested $field, string $scope, bool $editable): void
    {
        try {
            $related = $field->relationInstance()->getRelated();
        } catch (Throwable) {
            return;
        }

        $path = $scope.' › '.$field->name;
        $columns = $this->columns($related, $path);

        if ($columns === null) {
            return;
        }

        try {
            $this->fields($field->schema()->fields(), $related, $columns, $path, $editable);
        } catch (Throwable $e) {
            $this->add('error', $path, 'resource', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Поле можно записать без колонки: есть мутатор записи, а у readonly-поля — аксессор.
     */
    private function hasMutator(Model $model, Field $field): bool
    {
        return $model->hasSetMutator($field->name)
            || $model->hasAttributeSetMutator($field->name)
            || ($field->isReadonly() && ($model->hasGetMutator($field->name) || $model->hasAttributeGetMutator($field->name)));
    }

    /**
     * Касты, без которых поле не сохранит значение: массив для JSON-полей, enum для Enum.
     */
    private function casts(Field $field, Model $model, string $scope): void
    {
        $cast = $model->getCasts()[$field->name] ?? null;

        if ($field instanceof Enum && ! (is_string($cast) && enum_exists($cast) && is_a($cast, BackedEnum::class, true))) {
            $this->add('error', $scope, 'enum_cast', ['field' => $field->name, 'model' => $model::class], 'enum_cast_hint');
        }

        $spatie = method_exists($model, 'isTranslatableAttribute') && $model->isTranslatableAttribute($field->name);

        if ($this->needsArray($field) && ! $spatie && (! is_string($cast) || ! in_array($cast, self::ARRAY_CASTS, true) && ! str_contains($cast, '\\'))) {
            $this->add('error', $scope, 'array_cast', ['field' => $field->name, 'model' => $model::class], 'array_cast_hint');
        }
    }

    /**
     * Поле хранит массив в одной колонке.
     */
    private function needsArray(Field $field): bool
    {
        return $field->isTranslatable()
            || $field instanceof Gallery
            || ($field instanceof Tags && (! $field instanceof ChatIds || ! $field->isSingle()))
            || $field instanceof KeyValue
            || $field instanceof Repeater
            || $field instanceof Blocks
            || $field instanceof Seo
            || ($field instanceof Select && $field->isMultiple());
    }

    /**
     * Колонка и правила поля согласованы: NOT NULL без значения, длина строки.
     *
     * @param  array<string, mixed>  $column
     */
    private function column(Field $field, Model $model, array $column, string $scope, bool $editable): void
    {
        $hasDefault = $column['default'] !== null || $column['auto_increment'] === true || array_key_exists($field->name, $model->getAttributes()) || $this->hasFormDefault($field);

        if ($editable && $column['nullable'] === false && ! $hasDefault && $field->isWritable() && $field->isFormField() && ! $field->isRequired() && ! $this->neverEmpty($field)) {
            $this->add('warning', $scope, 'not_null', ['field' => $field->name], 'not_null_hint');
        }

        $type = is_string($column['type'] ?? null) ? $column['type'] : '';

        if ($editable && $field->isWritable() && ! $field->isTranslatable() && preg_match('/char(?:acter varying|\w*)?\((\d+)\)/i', $type, $match) === 1) {
            $this->textLength($field, (int) $match[1], $scope);
        }

        if ($field instanceof Number && $editable) {
            $this->numberRange($field, $type, $scope);
        }

        if ($field instanceof Select && $editable && ! $field->isMultiple() && $field->getDependsOn() === null && preg_match('/^enum\((.+)\)$/i', $type, $match) === 1) {
            $this->enumOptions($field, $match[1], $scope);
        }
    }

    /**
     * Длина текста поля против длины строки в базе: Text — по `maxLength()` (и обратно: предел не менялся, а колонка шире), Textarea — по нему же или «без ограничения».
     */
    private function textLength(Field $field, int $columnLength, string $scope): void
    {
        if ($field instanceof Text && $field->getMaxLength() > $columnLength) {
            $this->add('warning', $scope, 'max_length', ['field' => $field->name, 'length' => $field->getMaxLength(), 'column' => $columnLength], 'max_length_hint');
        }

        // Предел 255 по умолчанию не меняли, а колонка шире: длинные значения поле не пропустит. Свой предел класса (32 у Tel) — намеренный.
        if ($field instanceof Text && $field->getMaxLength() === 255 && $columnLength > 255 && new ReflectionProperty($field, 'maxLength')->getDefaultValue() === 255) {
            $this->add('warning', $scope, 'max_length_short', ['field' => $field->name, 'length' => $field->getMaxLength(), 'column' => $columnLength], 'max_length_short_hint');
        }

        if ($field instanceof Textarea) {
            $max = $field->getMaxLength();

            if ($max === null) {
                $this->add('warning', $scope, 'max_length_none', ['field' => $field->name, 'column' => $columnLength], 'max_length_hint');
            } elseif ($max > $columnLength) {
                $this->add('warning', $scope, 'max_length', ['field' => $field->name, 'length' => $max, 'column' => $columnLength], 'max_length_hint');
            }
        }
    }

    /**
     * Границы `min()`/`max()` числа против диапазона целочисленной или десятичной колонки (SQLite хранит любые целые — не проверяется).
     */
    private function numberRange(Number $field, string $type, string $scope): void
    {
        $range = $this->columnRange($type);

        if ($range === null) {
            return;
        }

        [$lowest, $highest] = $range;
        $min = $field->getMin();
        $max = $field->getMax();

        if ($max !== null && $max > $highest) {
            $this->add('warning', $scope, 'number_range', ['field' => $field->name, 'bound' => $this->number($max), 'limit' => $this->number($highest)], 'number_range_hint');
        }

        if ($min !== null && $min < $lowest) {
            $this->add('warning', $scope, 'number_range', ['field' => $field->name, 'bound' => $this->number($min), 'limit' => $this->number($lowest)], 'number_range_hint');
        }
    }

    /**
     * Число для текста сообщения без экспоненты.
     */
    private function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Наименьшее и наибольшее число, которое вмещает колонка, или null, если тип не ограничен или неизвестен.
     *
     * @return array{0: int|float, 1: int|float}|null
     */
    private function columnRange(string $type): ?array
    {
        if (preg_match('/^(?:decimal|numeric)\((\d+),\s*(\d+)\)(\s+unsigned)?/i', $type, $match) === 1) {
            $highest = 10 ** ((int) $match[1] - (int) $match[2]) - 10 ** -(int) $match[2];

            return [isset($match[3]) ? 0 : -$highest, $highest];
        }

        $bytes = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4];

        if (preg_match('/^(tinyint|smallint|mediumint|int|integer)\b(?:\(\d+\))?(\s+unsigned)?/i', $type, $match) !== 1 || DB::connection()->getDriverName() === 'sqlite') {
            return null;
        }

        $bits = $bytes[strtolower($match[1])] * 8;

        return isset($match[2]) ? [0, 2 ** $bits - 1] : [-(2 ** ($bits - 1)), 2 ** ($bits - 1) - 1];
    }

    /**
     * Варианты `options()` против значений enum-колонки MySQL.
     */
    private function enumOptions(Select $field, string $definition, string $scope): void
    {
        preg_match_all("/'((?:[^']|'')*)'/", $definition, $matches);
        $allowed = array_map(fn (string $value): string => str_replace("''", "'", $value), $matches[1]);

        try {
            $outside = array_values(array_diff(array_map(strval(...), array_keys($field->getOptions())), $allowed));
        } catch (Throwable) {
            return;
        }

        if ($outside !== []) {
            $this->add('warning', $scope, 'enum_options', ['field' => $field->name, 'values' => implode(', ', $outside)], 'enum_options_hint');
        }
    }

    /**
     * У поля есть значение по умолчанию в форме.
     */
    private function hasFormDefault(Field $field): bool
    {
        try {
            $value = $field->formValue(null);
        } catch (Throwable) {
            return false;
        }

        return ! in_array($value, [null, '', []], true);
    }

    /**
     * Поле всегда отправляет значение или получает его само (галочка, пароль, адрес из заголовка).
     */
    private function neverEmpty(Field $field): bool
    {
        // Адрес из заголовка заполняется сам только с from(); пароль при изменении записи не требуется.
        if ($field instanceof Password || ($field instanceof Slug && $field->getFrom() !== null)) {
            return true;
        }

        return array_any(self::NEVER_EMPTY, fn (string $class): bool => $field instanceof $class);
    }

    /**
     * Записать проблему.
     *
     * @param  'error'|'warning'  $level
     * @param  array<string, int|string>  $replace
     */
    private function add(string $level, string $scope, string $key, array $replace = [], ?string $hint = null): void
    {
        $this->problems[] = [
            'level' => $level,
            'scope' => $scope,
            'message' => trans()->string('fastleo::doctor.'.$key, $replace, Fastleo::LOCALE),
            'hint' => $hint === null ? null : trans()->string('fastleo::doctor.'.$hint, $replace, Fastleo::LOCALE),
        ];
    }
}
