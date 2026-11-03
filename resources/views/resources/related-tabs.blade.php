{{-- Панели вкладок с таблицами связанных записей (Related в Tab) на странице правки: вне <form> (в таблицах свои формы).
     Какая вкладка открыта, говорит полоса вкладок формы событием fastleo-tab (group — номер раскладки Tabs, tab — номер вкладки). --}}
@foreach ($resource->schema()->items() as $item)
    @if ($item instanceof \Fastleo\Layout\Tabs)
        @foreach ($item->visibleTabs($record) as $index => $tab)
            @if ($tab->isTable())
                <div
                    x-data="{ shown: false }"
                    x-show="shown"
                    x-cloak
                    @fastleo-tab.window="if ($event.detail.group === {{ spl_object_id($item) }}) shown = $event.detail.tab === {{ $index }}"
                    role="tabpanel"
                    data-tab-tables="{{ $tab->key() }}"
                >
                    @include('fastleo::resources.related-sections', ['relations' => $tab->tables(), 'withTab' => true, 'panel' => true])
                </div>
            @endif
        @endforeach
    @endif
@endforeach
