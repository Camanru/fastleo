<?php

declare(strict_types=1);

namespace Fastleo\Activity;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Password;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Пишет журнал изменений: слушает события Eloquent (`created`, `updated`, `deleted`, `restored`,
 * `forceDeleted`) моделей разделов, но только пока включено middleware `RecordActivity`, то есть
 * во время запроса администратора. Код сайта, консоль и очередь журнал не засоряют.
 *
 * Запись идёт в той же транзакции, что и изменение: откатилось изменение — откатилась и запись.
 *
 * ```php
 * $recorder->withEvent('reverted', fn () => $record->save()); // изменение значений пишется как «возврат»
 * ```
 *
 * Название подменяется только у изменения значений (`updated`): создание и восстановление при откате
 * удаления пишутся своими событиями — они не откатываются, а «возврат» откатывать можно только как значения.
 */
final class ActivityRecorder
{
    /** События Eloquent, которые пишутся в журнал. */
    private const array EVENTS = ['created', 'updated', 'deleted', 'restored', 'forceDeleted'];

    /** Служебные атрибуты, изменения которых в журнал не попадают. */
    private const array IGNORED = ['created_at', 'updated_at', 'deleted_at', 'remember_token'];

    private bool $listening = false;

    private bool $enabled = false;

    private ?string $event = null;

    /**
     * Класс модели → адрес раздела (по ресурсам, которые ведут журнал).
     *
     * @var array<class-string<Model>, string>|null
     */
    private ?array $map = null;

    /**
     * @param  Dispatcher  $events  события приложения
     */
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * Включить запись на время текущего запроса (если журнал не выключен в `fastleo.activity.enabled`).
     */
    public function start(): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $this->listen();
        $this->enabled = true;
    }

    /**
     * Выключить запись.
     */
    public function stop(): void
    {
        $this->enabled = false;
    }

    /**
     * Журнал включён в настройках.
     */
    public static function isEnabled(): bool
    {
        return config()->boolean('fastleo.activity.enabled', true);
    }

    /**
     * Администратору можно открывать общий журнал: журнал включён и Gate `viewFastleoActivity`
     * (если сайт его описал) разрешает.
     */
    public static function canView(): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        return ! Gate::has('viewFastleoActivity') || Gate::forUser(Fastleo::guard()->user())->allows('viewFastleoActivity');
    }

    /**
     * Выполнить действие, записывая его в журнал под другим названием события (например, `reverted`).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withEvent(string $event, Closure $callback): mixed
    {
        $this->event = $event;

        try {
            return $callback();
        } finally {
            $this->event = null;
        }
    }

    /**
     * Выполнить действие, не записывая изменения моделей в журнал (импорт пишет одну сводную запись).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutRecording(Closure $callback): mixed
    {
        $enabled = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $enabled;
        }
    }

    /**
     * Итог импорта одной записью: сколько создано, обновлено, пропущено (записи строк в журнал не пишутся).
     *
     * @param  array{created: int, updated: int, skipped: int, file: string}  $summary
     */
    public function importFinished(Resource $resource, array $summary): void
    {
        if (! self::isEnabled() || ! $resource::logsActivity()) {
            return;
        }

        $this->write($resource::slug(), null, null, 'import', array_map(fn (mixed $value): array => ['new' => $value], $summary), null);
    }

    /**
     * Подписать слушатели событий Eloquent (один раз за жизнь приложения).
     */
    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        foreach (self::EVENTS as $event) {
            $this->events->listen('eloquent.'.$event.': *', function (string $name, array $payload) use ($event): void {
                $model = $payload[0] ?? null;

                if ($this->enabled && $model instanceof Model) {
                    $this->record($event, $model);
                }
            });
        }
    }

    /**
     * Записать событие модели раздела.
     */
    private function record(string $event, Model $model): void
    {
        $slug = $this->slugFor($model);

        if ($slug === null) {
            return;
        }

        $hidden = array_values(array_merge($model->getHidden(), ['password', 'remember_token'], $this->passwordFields($slug)));
        $computed = $this->computedFields($slug);
        $soft = method_exists($model, 'isForceDeleting');
        $changes = null;
        $snapshot = null;

        switch ($event) {
            case 'created':
                $changes = $this->attributes($model, $hidden, null, computed: $computed);

                break;
            case 'updated':
                $changes = $this->attributes($model, $hidden, array_keys($model->getChanges()), true, $computed);

                if ($changes === []) {
                    return;
                }

                break;
            case 'deleted':
                if ($soft && $model->isForceDeleting()) {
                    return; // запишет forceDeleted
                }

                $snapshot = $soft ? null : $this->snapshot($model, $hidden, $computed);

                break;
            case 'forceDeleted':
                $snapshot = $this->snapshot($model, $hidden, $computed);

                break;
        }

        $this->write(
            $slug,
            is_scalar($model->getKey()) ? (string) $model->getKey() : null,
            $this->label($model),
            $event === 'updated' ? ($this->event ?? 'updated') : Str::snake($event),
            $changes,
            $snapshot,
        );
    }

    /**
     * Атрибуты модели для журнала: значения «было → стало» либо только факт изменения для скрытых.
     *
     * @param  list<string>  $hidden
     * @param  list<string>|null  $only  только эти атрибуты; null — все (создание)
     * @param  list<string>  $computed  вычисляемые столбцы из query() (`fromQuery()`): колонок в таблице нет
     * @return array<string, array{old?: mixed, new?: mixed, hidden?: bool}>
     */
    private function attributes(Model $model, array $hidden, ?array $only, bool $withOld = false, array $computed = []): array
    {
        $result = [];
        $attributes = $model->getAttributes();

        foreach ($only ?? array_keys($attributes) as $key) {
            if ($key === $model->getKeyName() || in_array($key, $computed, true) || in_array($key, self::IGNORED, true) || $key === $model->getCreatedAtColumn() || $key === $model->getUpdatedAtColumn()) {
                continue;
            }

            if (in_array($key, $hidden, true)) {
                $result[$key] = ['hidden' => true];

                continue;
            }

            $result[$key] = ['old' => $withOld ? $this->clean($model->getRawOriginal($key)) : null, 'new' => $this->clean($attributes[$key] ?? null)];
        }

        return $result;
    }

    /**
     * Копия записи без скрытых атрибутов — для восстановления удалённой.
     *
     * @param  list<string>  $hidden
     * @param  list<string>  $computed  вычисляемые столбцы из query() (`fromQuery()`): колонок в таблице нет
     * @return array<string, mixed>
     */
    private function snapshot(Model $model, array $hidden, array $computed = []): array
    {
        $snapshot = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (! in_array($key, $hidden, true) && ! in_array($key, $computed, true)) {
                $snapshot[$key] = $this->clean($value);
            }
        }

        return $snapshot;
    }

    /**
     * Значение, пригодное для JSON: скаляры как есть, не-UTF-8 строки очищаются.
     */
    private function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return is_scalar($value) || $value === null ? $value : json_encode($value);
    }

    /**
     * Краткое название записи для списка журнала: `title`, `name` и т. п., иначе `#ключ`.
     */
    private function label(Model $model): string
    {
        foreach (['title', 'name', 'heading', 'label', 'email'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 200, '…');
            }

            if (is_array($value) && is_string(reset($value)) && reset($value) !== '') {
                return Str::limit(reset($value), 200, '…');
            }
        }

        $key = $model->getKey();

        return '#'.(is_scalar($key) ? (string) $key : '');
    }

    /**
     * Имена полей `Password` раздела: хеш пароля в журнал не пишется, даже если колонки нет в `$hidden` модели.
     *
     * @return list<string>
     */
    private function passwordFields(string $slug): array
    {
        $resource = Fastleo::resources()->find($slug);

        if (! $resource instanceof Resource) {
            return [];
        }

        $names = [];

        foreach ($resource->schema()->fields() as $field) {
            if ($field instanceof Password) {
                $names[] = $field->name;
            }
        }

        return $names;
    }

    /**
     * Имена вычисляемых столбцов раздела (`fromQuery()`): значение приходит из query(), в таблице колонки нет — в журнал
     * и снимок удалённой записи не попадает (иначе возврат записи упал бы на несуществующей колонке).
     *
     * @return list<string>
     */
    private function computedFields(string $slug): array
    {
        $resource = Fastleo::resources()->find($slug);

        return $resource instanceof Resource
            ? array_values(array_map(fn (Field $field): string => $field->name, array_filter($resource->schema()->fields(), fn (Field $field): bool => $field->isFromQuery())))
            : [];
    }

    /**
     * Адрес раздела для модели или null, если она не принадлежит разделу с журналом.
     */
    private function slugFor(Model $model): ?string
    {
        if ($this->map === null) {
            $this->map = [];

            foreach (Fastleo::resources()->all() as $slug => $class) {
                if ($class::logsActivity() && ! isset($this->map[$class::model()])) {
                    $this->map[$class::model()] = $slug;
                }
            }
        }

        return $this->map[$model::class] ?? null;
    }

    /**
     * Вставить строку журнала от имени вошедшего администратора.
     *
     * @param  array<string, array<string, mixed>>|null  $changes
     * @param  array<string, mixed>|null  $snapshot
     */
    private function write(string $slug, ?string $subjectId, ?string $label, string $event, ?array $changes, ?array $snapshot): void
    {
        $user = Fastleo::guard()->user();
        $name = $user instanceof Model ? $user->getAttribute('name') : null;
        $key = $user?->getAuthIdentifier();

        Activity::query()->create([
            'user_id' => is_scalar($key) ? (string) $key : null,
            'user_name' => is_string($name) ? Str::limit($name, 250, '') : null,
            'resource' => $slug,
            'subject_id' => $subjectId,
            'subject_label' => $label,
            'event' => $event,
            'changes' => $changes === [] ? null : $changes,
            'snapshot' => $snapshot,
            'created_at' => now(),
        ]);
    }

    /**
     * Показывается ли раздел в общем журнале администратору (раздел виден по Policy).
     */
    public static function visibleTo(Resource $resource): bool
    {
        return $resource::logsActivity() && Authorization::allows('viewAny', $resource);
    }
}
