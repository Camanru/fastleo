<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\Menu;
use Fastleo\Support\ResourceQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Глобальный поиск (Ctrl+K): пункты меню по названию и записи разделов по полям `searchable()`.
 *
 * Раздел участвует, если администратору доступно `viewAny` и раздел не выключил `$globallySearchable`;
 * каждая найденная запись проходит Policy `view`. До 5 записей на раздел.
 */
final class SearchController
{
    /** Записей одного раздела в ответе. */
    private const int PER_RESOURCE = 5;

    /** Длина строки поиска. */
    private const int MIN_LENGTH = 2;

    private const int MAX_LENGTH = 100;

    /**
     * Результаты поиска по `?q=` (JSON): группы `{label, items: [{title, hint, url}]}`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Пустое значение Laravel превращает в null — это «ничего не ввели», а не ошибка.
        $query = $request->query('q') ?? '';
        abort_unless(is_string($query) && mb_strlen(trim($query)) <= self::MAX_LENGTH, 422);

        $query = trim($query);

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return response()->json(['groups' => []]);
        }

        $groups = array_values(array_filter([$this->sections($query), ...$this->records($query)]));

        return response()->json(['groups' => $groups]);
    }

    /**
     * Пункты меню (разделы, настройки, страницы), в названии которых есть строка поиска.
     *
     * @return array{label: string, items: list<array{title: string, hint: string, url: string}>}|null
     */
    private function sections(string $query): ?array
    {
        $items = [];

        foreach (Menu::groups() as $group) {
            foreach ($group['items'] as $item) {
                if (mb_stripos($item['label'], $query) !== false) {
                    $items[] = ['title' => $item['label'], 'hint' => $group['label'] ?? '', 'url' => $item['url']];
                }
            }
        }

        return $items === [] ? null : ['label' => __('fastleo::search.sections'), 'items' => $items];
    }

    /**
     * Найденные записи по разделам.
     *
     * @return list<array{label: string, items: list<array{title: string, hint: string, url: string}>}|null>
     */
    private function records(string $query): array
    {
        $groups = [];

        foreach (array_keys(Fastleo::resources()->all()) as $slug) {
            $resource = Fastleo::resources()->find($slug);

            if (! $resource instanceof Resource || ! $resource::isGloballySearchable() || ! Authorization::allows('viewAny', $resource)) {
                continue;
            }

            $search = new ResourceQuery($resource, Request::create('/', 'GET', ['q' => $query]), withDefaults: false);

            if (! $search->hasSearch()) {
                continue;
            }

            $items = [];

            foreach ($search->query()->limit(self::PER_RESOURCE)->get() as $record) {
                if (Authorization::allows('view', $resource, $record)) {
                    $items[] = [
                        'title' => $this->title($resource, $record),
                        'hint' => $resource::singular(),
                        'url' => $this->url($resource, $record),
                    ];
                }
            }

            $groups[] = $items === [] ? null : ['label' => $resource::label(), 'items' => $items];
        }

        return $groups;
    }

    /**
     * Название записи: `Resource::title()` (до 100 символов), иначе ключ.
     */
    private function title(Resource $resource, Model $record): string
    {
        $text = $resource->title($record);

        if ($text === '') {
            $key = $record->getKey();
            $text = is_scalar($key) ? (string) $key : '';
        }

        return Str::limit($text, 100);
    }

    /**
     * Ссылка на запись: форма, если можно менять, иначе просмотр.
     */
    private function url(Resource $resource, Model $record): string
    {
        return Authorization::allows('update', $resource, $record)
            ? route('fastleo.resources.edit', [$resource::slug(), $record->getKey()])
            : route('fastleo.resources.show', [$resource::slug(), $record->getKey()]);
    }
}
