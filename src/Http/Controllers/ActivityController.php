<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Activity\Activity;
use Fastleo\Activity\ActivityRecorder;
use Fastleo\Activity\Reverter;
use Fastleo\Activity\RevertFailed;
use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Журнал изменений: общий список, история одной записи и откат.
 *
 * В общем журнале видны только разделы, которые администратору можно открывать (`viewAny`);
 * общий журнал можно закрыть Gate `viewFastleoActivity`.
 */
final readonly class ActivityController
{
    /** Записей журнала на странице. */
    private const int PER_PAGE = 50;

    /**
     * @param  Reverter  $reverter  откат записей журнала
     */
    public function __construct(private Reverter $reverter) {}

    /**
     * Общий журнал с фильтрами по разделу и автору.
     */
    public function index(Request $request): View
    {
        abort_unless(ActivityRecorder::canView(), 403);

        $resources = $this->visibleResources();
        $query = Activity::query()->whereIn('resource', array_keys($resources))->latest('id');

        $slug = $request->query('resource');
        $author = $request->query('user');

        if (is_string($slug) && isset($resources[$slug])) {
            $query->where('resource', $slug);
        }

        if (is_string($author) && preg_match('/^[0-9A-Za-z-]{1,36}$/', $author) === 1) {
            $query->where('user_id', $author);
        }

        $entries = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('fastleo::activity.index', [
            'entries' => $entries,
            'hiddenEntries' => $this->hiddenEntries($entries->items(), $resources),
            'resources' => $resources,
            'authors' => Activity::query()->whereNotNull('user_id')->whereIn('resource', array_keys($resources))->distinct()->limit(100)->pluck('user_name', 'user_id'),
            'filters' => ['resource' => is_string($slug) ? $slug : '', 'user' => is_string($author) ? $author : ''],
        ]);
    }

    /**
     * История одной записи. Работает и для удалённой: нужно право на просмотр раздела.
     */
    public function history(string $resource, string $id): View
    {
        $resource = $this->resource($resource);
        Authorization::authorize('viewAny', $resource);

        abort_unless(preg_match('/^[0-9A-Za-z-]{1,36}$/', $id) === 1, 404);

        $record = $resource->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($id)->first();

        if ($record instanceof Model) {
            Authorization::authorize('view', $resource, $record);
        } else {
            // Запись есть, но вне выборки `query()` раздела — история ей недоступна, как и сама запись.
            abort_if($resource->newModel()->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($id)->exists(), 404);
        }

        $entries = Activity::query()->where('resource', $resource::slug())->where('subject_id', $id)->latest('id')->paginate(self::PER_PAGE);

        abort_if($record === null && $entries->isEmpty(), 404);

        return view('fastleo::activity.history', [
            'resource' => $resource,
            'record' => $record,
            'recordId' => $id,
            'entries' => $entries,
            'resources' => [$resource::slug() => $resource],
        ]);
    }

    /**
     * Откатить запись журнала.
     */
    public function revert(int $activity): RedirectResponse
    {
        $entry = Activity::query()->findOrFail($activity);
        $resource = $this->resource($entry->resource);
        Authorization::authorize('viewAny', $resource);

        try {
            $this->reverter->revert($entry, $resource);
        } catch (RevertFailed $exception) {
            return back(fallback: route('fastleo.activity.index'))->with('fastleo.error', $exception->getMessage());
        }

        return back(fallback: route('fastleo.activity.index'))->with('fastleo.success', __('fastleo::activity.reverted'));
    }

    /**
     * Разделы с журналом, доступные администратору: адрес → ресурс.
     *
     * @return array<string, Resource>
     */
    private function visibleResources(): array
    {
        $visible = [];

        foreach (array_keys(Fastleo::resources()->all()) as $slug) {
            $resource = Fastleo::resources()->find($slug);

            if ($resource instanceof Resource && ActivityRecorder::visibleTo($resource)) {
                $visible[$slug] = $resource;
            }
        }

        return $visible;
    }

    /**
     * Записи журнала, о которых администратор не должен читать: запись существует, но вне выборки `query()`
     * раздела или Policy не даёт её смотреть. Удалённые насовсем записи проверить нечем — они показываются.
     *
     * @param  array<array-key, Activity>  $entries
     * @param  array<string, Resource>  $resources
     * @return list<int>
     */
    private function hiddenEntries(array $entries, array $resources): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            if ($entry->subject_id !== null) {
                $ids[$entry->resource][] = $entry->subject_id;
            }
        }

        $hidden = [];

        foreach ($ids as $slug => $subjects) {
            $resource = $resources[$slug] ?? null;

            if (! $resource instanceof Resource) {
                continue;
            }

            $scoped = $resource->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($subjects)->get()->keyBy(function (Model $record): string {
                $key = $record->getKey();

                return is_scalar($key) ? (string) $key : '';
            });
            $existing = $resource->newModel()->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($subjects)->pluck($resource->newModel()->getKeyName())->map(fn (mixed $key): string => is_scalar($key) ? (string) $key : '')->flip();

            foreach ($entries as $entry) {
                if ($entry->resource !== $slug || $entry->subject_id === null || ! $existing->has($entry->subject_id)) {
                    continue;
                }

                $record = $scoped->get($entry->subject_id);

                if (! $record instanceof Model || ! Authorization::allows('view', $resource, $record)) {
                    $hidden[] = $entry->id;
                }
            }
        }

        return $hidden;
    }

    /**
     * Раздел с журналом по адресу или 404.
     */
    private function resource(string $slug): Resource
    {
        $resource = Fastleo::resources()->find($slug);
        abort_unless($resource instanceof Resource && $resource::logsActivity() && ActivityRecorder::isEnabled(), 404);

        return $resource;
    }
}
