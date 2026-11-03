{{-- Связанные таблицы под формой на странице записи ($resource, $record): каждая подгружается отдельным запросом
     (и обновляется им же), поэтому поиск, сортировка и страницы таблиц не мешают друг другу и странице.
     Таблица показывается, если можно открыть запись родителя и список дочернего раздела.
     $only — 'main' или 'aside': только таблицы основной или боковой колонки (по умолчанию все).
     Таблицы из вкладок формы (Related в Tab) выводит только related-tabs: здесь они появляются при $withTab (страница просмотра)
     и $nested (раскрытая строка). $relations — показать только эти таблицы (панель вкладки); $panel — таблица лежит в панели вкладки формы (название ей даёт вкладка, заголовка нет).
     $nested — внутри раскрытой строки (без верхнего отступа); $returnUrl — страница, на которую возвращают «Добавить» и действия
     (по умолчанию текущая). --}}
@foreach ($relations ?? ($resource->showsRelations($record) ? $resource->listRelations() : []) as $related)
    @if ((($only ?? null) === null || ($only === 'aside') === $related->isAside()) && (! $related->isInTab() || ($withTab ?? false) || ($nested ?? false)))
    @if (\Fastleo\Support\Authorization::allows('view', $resource, $record) && \Fastleo\Support\Authorization::allows('viewAny', $related->child()))
        <section
            x-data="fastleoRelated({ url: @js(route('fastleo.resources.related', [$resource::slug(), $record->getKey(), $related->name, 'return' => ($returnUrl ?? url()->current()).($related->isInTab() ? '#'.$related->tabKey() : ''), 'panel' => ($panel ?? false) ? 1 : null])), error: @js(__('fastleo::resources.related_error')), poll: @js($related->child()::poll()) })"
            @class(['flex flex-col gap-3', 'mt-6' => ! ($nested ?? false)])
            data-related="{{ $related->name }}"
            aria-live="polite"
        >
            <div x-ref="body">
                <div class="fastleo-card p-5 text-sm text-ink-2" data-related-loading>{{ __('fastleo::resources.related_loading') }}</div>
            </div>
        </section>
    @endif
    @endif
@endforeach
