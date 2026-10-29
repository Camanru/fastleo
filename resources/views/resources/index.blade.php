{{-- Список записей раздела: поиск, сортировка, корзина, таблица (на телефоне — карточки), страницы.
     Дерево ($treeColumn): отступы, сворачивание, ветка ?parent=, «+» вложенная. Ручной порядок ($sortColumn): стрелки и перетаскивание. --}}
@php
    $slug = $resource::slug();
    $tree = $list->isTree() ? $list->tree() : null;
    $manual = $list->isManualOrder() && ! $resource::isReadonly();
    $treeColumn = $resource::treeColumn();
    $key = fn ($record): string => (string) $record->getKey();
    $depth = fn ($record): int => $tree?->depth($key($record), $list->parent) ?? 0;
    $ancestors = fn ($record): string => implode(' ', $tree?->ancestors($key($record), $list->parent) ?? []);
    $children = fn ($record): int => $tree?->childCount($key($record)) ?? 0;
    // Права строки считаются один раз: таблица, карточки для телефона и кнопки строки спрашивают одно и то же.
    $memo = [];
    $allows = function (string $ability, $record = null) use ($resource, &$memo): bool {
        return $memo[$ability.'|'.($record?->getKey() ?? '')] ??= \Fastleo\Support\Authorization::allows($ability, $resource, $record);
    };
    $canReorder = fn ($record): bool => $manual && ($memo['reorder|'.$record->getKey()] ??= \Fastleo\Support\Authorization::allowsReorder($resource, $record));
    $parentOf = fn ($record): string => $treeColumn === null ? '' : (string) $record->getAttribute($treeColumn);
    $fields = $resource->listFields();
    $canCreate = ! $trashed && $allows('create');
    $sortUrl = function (string $name) use ($list): string {
        $direction = $list->sort === $name && $list->direction === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $name, 'direction' => $direction, 'page' => null]);
    };
    $titleField = $fields[0] ?? null;
    // Действия: в полосе выбранных — только те, что разрешены хотя бы для одной записи страницы.
    $actionAllows = function ($action, $record) use (&$memo): bool {
        return $memo['action|'.spl_object_id($action).'|'.$record->getKey()] ??= $action->allows($record);
    };
    $bulkActions = array_filter($resource->actionsFor(true, $trashed), fn ($action) => $records->contains(fn ($record) => $actionAllows($action, $record)));
    // Кнопки строки одинаковы в таблице и в карточках для телефона: рисуются один раз на запись.
    $actionsHtml = [];
    $renderActions = function ($record, array $data) use (&$actionsHtml): string {
        return $actionsHtml[$record->getKey()] ??= view('fastleo::resources.row-actions', $data)->render();
    };
    $singleActions = $resource->actionsFor(false, $trashed);
    $formActions = array_filter($resource->listActions(), fn ($action) => $action->isForTrash() === $trashed);
    $selectable = $bulkActions !== [];
    $pageIds = $records->map(fn ($record) => (string) $record->getKey())->values()->all();
    $recordUrl = function ($record) use ($allows, $slug, $trashed): ?string {
        if (! $trashed && $allows('update', $record)) {
            return route('fastleo.resources.edit', [$slug, $record->getKey()]);
        }

        return $allows('view', $record)
            ? route('fastleo.resources.show', [$slug, $record->getKey()])
            : null;
    };
@endphp
<x-fastleo::layouts.app :title="$resource::label()" :description="$resource::description()" :count="$records->total()">
    <x-slot:actions>
        @if ($resource::isExportable() && \Fastleo\Support\Authorization::allowsOwnOr('export', 'viewAny', $resource))
            @php
                $exportQuery = \Illuminate\Support\Arr::except(request()->query(), ['page', 'per_page', 'parent', 'resource', 'format']);
            @endphp
            <div x-data="fastleoMenu" @keydown.escape="menu = false" @click.outside="menu = false" @scroll.window="place()">
                <x-fastleo::button icon="download" x-ref="button" x-on:click="toggle()" x-bind:aria-expanded="menu" aria-haspopup="menu">
                    {{ __('fastleo::resources.export') }}
                </x-fastleo::button>
                <div x-show="menu" x-cloak role="menu" :style="style" class="fixed z-40 w-48 rounded-[10px] border border-line bg-surface p-1 text-base shadow-pop">
                    @foreach (\Fastleo\Exports\Exporter::FORMATS as $format)
                        <a role="menuitem" href="{{ route('fastleo.resources.export', ['resource' => $slug, ...$exportQuery, 'format' => $format]) }}" class="flex h-8 items-center rounded-[6px] px-2 hover:bg-hover" x-on:click="menu = false">
                            {{ __('fastleo::resources.export_'.$format) }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($resource::isImportable() && ! $trashed && \Fastleo\Support\Authorization::allowsOwnOr('import', 'viewAny', $resource))
            <x-fastleo::button icon="upload" x-data x-on:click="document.getElementById('fastleo-import').showModal()" x-init="{{ $errors->has('file') ? '$el.click()' : '' }}">
                {{ __('fastleo::imports.button') }}
            </x-fastleo::button>
            @include('fastleo::imports.dialog')
        @endif

        @if ($canCreate)
            <x-fastleo::button variant="primary" icon="plus" :href="route('fastleo.resources.create', array_filter(['resource' => $slug, 'parent' => $branch?->getKey()]))">
                {{ __('fastleo::resources.create') }}
            </x-fastleo::button>
        @endif
    </x-slot:actions>

    <x-fastleo::flash />

    <div class="flex flex-col gap-5" x-data="fastleoActions({ ids: @js($pageIds), open: @js(session('fastleo.action')), openIds: @js(array_values(array_filter((array) old('ids', []), 'is_string'))) })">
    @include('fastleo::actions.forms', ['actions' => $formActions])

    <div class="flex flex-col gap-3" x-data="{ filtersOpen: @js($list->filters !== []), filtersApplied: @js($list->filters !== []) }">
    <div class="flex flex-wrap items-center gap-2">
        @if ($list->hasSearch() || $list->hasFilters())
            <form id="fastleo-list" method="GET" action="{{ route('fastleo.resources.index', $slug) }}" role="search" class="flex w-full min-w-0 items-center gap-2 sm:w-auto sm:flex-[0_1_460px]">
                @if ($trashed)<input type="hidden" name="trashed" value="1">@endif
                @if ($list->sort !== null)
                    <input type="hidden" name="sort" value="{{ $list->sort }}">
                    <input type="hidden" name="direction" value="{{ $list->direction }}">
                @endif
                @if ($list->all)<input type="hidden" name="per_page" value="all">@endif
                {{-- Enter в поиске отправляет форму, даже когда в панели фильтров есть поля дат. --}}
                <button type="submit" class="sr-only" tabindex="-1">{{ __('fastleo::resources.search') }}</button>
                @if ($list->hasSearch())
                    <label class="relative min-w-0 flex-1">
                        <span class="sr-only">{{ __('fastleo::resources.search') }}</span>
                        <x-fastleo::icon name="search" class="pointer-events-none absolute top-1/2 left-[11px] size-4 -translate-y-1/2 text-ink-3" />
                        <input type="search" name="q" value="{{ $list->search }}" placeholder="{{ __('fastleo::resources.search_placeholder') }}" class="fastleo-input h-10 pl-9 sm:h-9">
                    </label>
                @endif
                @if ($list->hasFilters())
                    <x-fastleo::button icon="list-filter" class="h-10 sm:h-9" x-bind:class="(filtersOpen || filtersApplied) && 'border-transparent bg-primary-soft text-primary-text'" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="fastleo-filters">
                        <span class="max-sm:sr-only">{{ __('fastleo::resources.filters') }}</span>
                        @if ($list->filters !== [])<span class="flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[11px] font-semibold text-white tabular-nums" data-filters-count>{{ count($list->filters) }}</span>@endif
                    </x-fastleo::button>
                @endif
                @if ($list->isNarrowed())
                    <x-fastleo::button variant="ghost" :href="route('fastleo.resources.index', array_filter(['resource' => $slug, 'trashed' => $trashed ? 1 : null]))">
                        {{ __('fastleo::resources.search_reset') }}
                    </x-fastleo::button>
                @endif
            </form>
        @endif

        <div class="flex-1"></div>

        @if ($tree && $records->isNotEmpty())
            {{-- «Свернуть всё», пока ни одна ветка не свёрнута, иначе «Развернуть всё». --}}
            <div x-data="{ expanded: JSON.parse(localStorage.getItem(@js('fastleo.tree.'.$slug)) || '[]').length === 0 }" @fastleo-tree-changed.window="expanded = $event.detail">
                <x-fastleo::button variant="ghost" x-on:click="expanded = ! expanded; $dispatch('fastleo-tree', expanded ? 'expand' : 'collapse')" title="{{ __('fastleo::resources.tree_toggle_all') }}">
                    <x-fastleo::icon name="chevrons-down-up" class="size-4 shrink-0" x-show="expanded" />
                    <x-fastleo::icon name="chevrons-up-down" class="size-4 shrink-0" x-cloak x-show="! expanded" />
                    <span class="max-lg:sr-only" x-text="expanded ? @js(__('fastleo::resources.tree_collapse_all')) : @js(__('fastleo::resources.tree_expand_all'))">{{ __('fastleo::resources.tree_collapse_all') }}</span>
                </x-fastleo::button>
            </div>
        @endif

        <div x-data="fastleoDensity" class="hidden md:block">
            <x-fastleo::button variant="icon" x-on:click="toggle()" x-bind:aria-pressed="compact" title="{{ __('fastleo::resources.density') }}">
                <span class="sr-only">{{ __('fastleo::resources.density') }}</span>
                <x-fastleo::icon name="rows-3" class="size-[17px] compact:hidden" />
                <x-fastleo::icon name="rows-4" class="hidden size-[17px] compact:block" />
            </x-fastleo::button>
        </div>

        @if ($resource::usesSoftDeletes())
            <div class="flex rounded-[9px] bg-sunken p-0.5 text-sm font-medium">
                <a href="{{ route('fastleo.resources.index', $slug) }}" @class(['rounded-[7px] px-3 py-1.5', 'bg-surface shadow-[0_1px_2px_rgb(0_0_0/.08)]' => ! $trashed, 'text-ink-2' => $trashed]) @if (! $trashed) aria-current="page" @endif>
                    {{ __('fastleo::resources.list') }}
                </a>
                <a href="{{ route('fastleo.resources.index', ['resource' => $slug, 'trashed' => 1]) }}" @class(['rounded-[7px] px-3 py-1.5', 'bg-surface shadow-[0_1px_2px_rgb(0_0_0/.08)]' => $trashed, 'text-ink-2' => ! $trashed]) @if ($trashed) aria-current="page" @endif>
                    {{ __('fastleo::resources.trash') }}
                </a>
            </div>
        @endif
    </div>

    @if ($list->hasFilters())
        <div id="fastleo-filters" x-show="filtersOpen" x-cloak x-transition.opacity class="fastleo-card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($resource->listFilters() as $name => $filter)
                @include($filter->view(), ['filter' => $filter, 'value' => $list->filters[$name] ?? null, 'form' => 'fastleo-list'])
            @endforeach
        </div>
    @endif
    </div>

    @if (! $manual && $resource::sortColumn() !== null && ! $resource::isReadonly() && ! $trashed && $list->sort !== null && $records->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2.5 rounded-[10px] bg-info-soft px-3.5 py-2.5 text-sm text-info" data-order-notice>
            <x-fastleo::icon name="grip-vertical" class="size-4 shrink-0" />
            {{ __('fastleo::resources.sorted_order_hint') }}
            <a href="{{ request()->fullUrlWithQuery(['sort' => null, 'direction' => null, 'page' => null]) }}" class="font-medium underline">{{ __('fastleo::resources.search_reset') }}</a>
        </div>
    @endif

    @if ($manual && $records->isNotEmpty())
        <div class="flex items-center gap-2.5 rounded-[10px] bg-info-soft px-3.5 py-2.5 text-sm text-info" data-order-notice>
            <x-fastleo::icon name="grip-vertical" class="size-4 shrink-0" />
            {{ __('fastleo::resources.order_hint') }}
        </div>
    @endif

    @if ($trashed)
        <div class="flex items-center gap-2.5 rounded-[10px] bg-warning-soft px-3.5 py-2.5 text-sm text-warning" data-trash-notice>
            <x-fastleo::icon name="trash-2" class="size-4 shrink-0" />
            {{ __('fastleo::resources.trash_notice') }}
        </div>
    @endif

    @if ($branch)
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium">{{ __('fastleo::resources.tree_branch', ['title' => strip_tags((string) (($fields[0] ?? null)?->display($fields[0]->shownRecord($branch)) ?? $branch->getKey()))]) }}</span>
            <a href="{{ route('fastleo.resources.index', $slug) }}" class="text-primary-text hover:underline">{{ __('fastleo::resources.tree_show_all') }}</a>
        </div>
    @endif

    @if ($records->isEmpty())
        <div class="flex flex-col items-center gap-1.5 rounded-card border border-line bg-surface px-6 py-[72px] text-center">
            <div class="relative mb-4 h-[72px] w-[92px]" aria-hidden="true">
                <div class="absolute top-3 left-1.5 size-14 -rotate-[8deg] rounded-[14px] bg-sunken"></div>
                <div class="absolute top-1 left-[30px] flex size-14 items-center justify-center rounded-[14px] border border-line-strong bg-surface text-primary-text shadow-pop">
                    <x-fastleo::icon :name="$list->isNarrowed() ? 'search-x' : $resource::icon()" class="size-[26px]" />
                </div>
            </div>
            <h3 class="text-lg font-semibold tracking-[-0.01em]">{{ $list->isNarrowed() ? __('fastleo::resources.nothing_found') : __($trashed ? 'fastleo::resources.trash_empty' : 'fastleo::resources.empty') }}</h3>
            @if ($list->isNarrowed())
                <x-fastleo::button class="mt-3.5" :href="route('fastleo.resources.index', array_filter(['resource' => $slug, 'trashed' => $trashed ? 1 : null]))">{{ __('fastleo::resources.search_reset') }}</x-fastleo::button>
            @elseif ($canCreate)
                <x-fastleo::button class="mt-3.5" variant="primary" icon="plus" :href="route('fastleo.resources.create', array_filter(['resource' => $slug, 'parent' => $branch?->getKey()]))">{{ __('fastleo::resources.create') }}</x-fastleo::button>
            @endif
        </div>
    @else
    @if ($selectable)
        <div
            x-show="selected.length"
            x-cloak
            x-transition:enter="transition duration-200 ease-out-quint"
            x-transition:enter-start="translate-y-3 opacity-0"
            class="fixed inset-x-0 bottom-4 z-30 mx-auto flex w-max max-w-[calc(100vw-1.5rem)] flex-wrap items-center justify-center gap-0.5 rounded-card bg-inverse p-1 text-base text-on-inverse shadow-[0_10px_30px_rgb(0_0_0/.28)] lg:bottom-7"
            data-bulk-bar
        >
            <span class="pr-1.5 pl-2 font-semibold tabular-nums" x-text="@js(__('fastleo::actions.selected', ['count' => '__N__'])).replace('__N__', selected.length)"></span>
            <span class="h-5 w-px bg-current opacity-20"></span>
            @foreach ($bulkActions as $key => $action)
                <button
                    type="button"
                    @class(['inline-flex h-[34px] items-center gap-1.5 rounded-[8px] px-2.5 font-medium hover:bg-white/10 dark:hover:bg-black/5', 'text-[#ff8a80] dark:text-[#c0302a]' => $action->isDanger()])
                    x-on:click="run({{ \Illuminate\Support\Js::from($key) }}, selected, {{ \Illuminate\Support\Js::from($action->clientOptions()) }})"
                >
                    <x-fastleo::icon :name="$action->getIcon()" class="size-4" />
                    {{ $action->getLabel() }}
                </button>
            @endforeach
            <button type="button" class="inline-flex h-[34px] items-center gap-1.5 rounded-[8px] px-2.5 font-medium opacity-75 hover:bg-white/10 hover:opacity-100 dark:hover:bg-black/5" x-on:click="selected = []">
                <x-fastleo::icon name="x" class="size-4" />
                {{ __('fastleo::actions.clear_selection') }}
            </button>
        </div>
    @endif
    <div
        x-data="fastleoList({ key: @js('fastleo.tree.'.$slug), url: @js($manual ? route('fastleo.resources.reorder', $slug) : null), tree: @js($tree !== null) })"
        @fastleo-tree.window="$event.detail === 'collapse' ? collapseAll() : expandAll()"
    >
        {{-- Таблица: планшет и компьютер --}}
        <div class="hidden overflow-hidden rounded-card border border-line bg-surface shadow-card md:block">
            <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-0 text-left text-base">
                <thead>
                    <tr class="[&>th]:h-10 [&>th]:border-b [&>th]:border-line [&>th]:bg-surface-2 [&>th]:px-3 [&>th]:text-xs [&>th]:font-[550] [&>th]:whitespace-nowrap [&>th]:text-ink-2">
                        @if ($selectable)
                            <th scope="col" class="w-px !pl-4">
                                <input type="checkbox" class="size-4 rounded accent-primary" :checked="allSelected" :indeterminate="selected.length > 0 && ! allSelected" x-on:change="toggleAll($event.target.checked)" aria-label="{{ __('fastleo::actions.select_all') }}">
                            </th>
                        @endif
                        @if ($manual)
                            <th scope="col" class="w-px !px-2"><span class="sr-only">{{ __('fastleo::resources.order_drag') }}</span></th>
                        @endif
                        @foreach ($fields as $field)
                            <th scope="col" @class(['!text-right' => $field instanceof \Fastleo\Fields\Number]) @if ($list->sort === $field->name) aria-sort="{{ $list->direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                @if ($field->isSortable())
                                    <a href="{{ $sortUrl($field->name) }}" @class(['inline-flex items-center gap-1 hover:text-ink', 'font-[650] text-ink' => $list->sort === $field->name])>
                                        {{ $field->getLabel() }}
                                        <x-fastleo::icon :name="$list->sort !== $field->name ? 'chevrons-up-down' : ($list->direction === 'asc' ? 'arrow-up' : 'arrow-down')" @class(['size-[13px]', 'opacity-50' => $list->sort !== $field->name, 'text-primary-text' => $list->sort === $field->name]) />
                                    </a>
                                @else
                                    {{ $field->getLabel() }}
                                @endif
                            </th>
                        @endforeach
                        <th scope="col"><span class="sr-only">{{ __('fastleo::resources.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody x-ref="rows" data-start="{{ $records->firstItem() }}" data-first-page="{{ $records->onFirstPage() ? '1' : '0' }}" data-last-page="{{ $records->hasMorePages() ? '0' : '1' }}">
                    @foreach ($records as $record)
                        @php
                            $url = $recordUrl($record);
                            $childCount = $children($record);
                        @endphp
                        <tr
                            @class(['group/row hover:bg-hover aria-selected:bg-primary-soft [&:last-child>td]:border-b-0 [&>td]:border-b [&>td]:border-line [&>td]:px-3 [&>td]:py-2.5 compact:[&>td]:py-1.5', 'cursor-pointer' => $url !== null])
                            @if ($url) data-url="{{ $url }}" @endif
                            data-record="{{ $record->getKey() }}"
                            data-parent="{{ $parentOf($record) }}"
                            @if ($selectable) :aria-selected="selected.includes(@js((string) $record->getKey()))" @endif
                            @if ($tree) data-ancestors="{{ $ancestors($record) }}" x-show="visible($el)" @endif
                        >
                            @if ($selectable)
                                <td class="w-px !pl-4"><input type="checkbox" class="size-4 rounded accent-primary" value="{{ $record->getKey() }}" x-model="selected" aria-label="{{ __('fastleo::actions.select_record') }}"></td>
                            @endif
                            @if ($manual)
                                <td class="!px-2 whitespace-nowrap">@include('fastleo::resources.order-controls', ['handle' => true, 'arrows' => false, 'position' => $tree ? null : $records->firstItem() + $loop->index])</td>
                            @endif
                            @foreach ($fields as $field)
                                <td @class(['max-w-xs truncate', 'text-right tabular-nums' => $field instanceof \Fastleo\Fields\Number]) @if ($loop->first && $tree) style="padding-left: {{ 1 + $depth($record) * 1.5 }}rem" @endif>
                                    @if ($loop->first && $tree)
                                        @include('fastleo::resources.tree-toggle')
                                        <x-fastleo::icon :name="$childCount > 0 ? 'folder' : 'file-text'" class="mr-1.5 inline size-4 align-middle text-ink-3" />
                                    @endif
                                    @if ($loop->first && $url)
                                        <a href="{{ $url }}" class="font-medium text-ink hover:text-primary-text">@include('fastleo::resources.cell', ['inList' => true])</a>
                                    @else
                                        @include('fastleo::resources.cell', ['inList' => true])
                                    @endif
                                    @if ($loop->first && $childCount > 0)
                                        <a href="{{ route('fastleo.resources.index', ['resource' => $slug, 'parent' => $record->getKey()]) }}" class="ml-1 rounded-full bg-sunken px-1.5 text-xs font-[550] text-ink-2 tabular-nums hover:text-ink" title="{{ __('fastleo::resources.tree_children', ['count' => $childCount]) }}">{{ $childCount }}</a>
                                    @endif
                                </td>
                            @endforeach
                            <td class="!px-2 !py-1.5">
                                <div class="flex items-center justify-end gap-0.5">
                                    @if ($manual)
                                        @include('fastleo::resources.order-controls', ['handle' => false, 'first' => ! $tree && $loop->first && $records->onFirstPage(), 'last' => ! $tree && $loop->last && ! $records->hasMorePages()])
                                    @endif
                                    {!! $renderActions($record, \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path'])) !!}
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @include('fastleo::partials.pagination', ['paginator' => $records, 'all' => $list->all])
        </div>

        {{-- Карточки: телефон --}}
        <ul class="flex flex-col gap-2.5 md:hidden" data-cards>
            @foreach ($records as $record)
                @php
                    $url = $recordUrl($record);
                    $childCount = $children($record);
                @endphp
                <li
                    @if ($url) data-url="{{ $url }}" @endif
                    class="fastleo-card p-3.5 aria-selected:border-primary aria-selected:bg-[color-mix(in_srgb,var(--color-primary)_7%,var(--color-surface))]"
                    data-record="{{ $record->getKey() }}"
                    @if ($selectable) :aria-selected="selected.includes(@js((string) $record->getKey()))" @endif
                    @if ($tree) data-ancestors="{{ $ancestors($record) }}" x-show="visible($el)" style="margin-left: {{ min($depth($record), 4) }}rem" @endif
                >
                    @if ($tree && $childCount > 0)
                        <div class="mb-2 flex items-center gap-2 text-xs text-ink-2">
                            @include('fastleo::resources.tree-toggle')
                            <a href="{{ route('fastleo.resources.index', ['resource' => $slug, 'parent' => $record->getKey()]) }}" class="hover:underline">{{ __('fastleo::resources.tree_children', ['count' => $childCount]) }}</a>
                        </div>
                    @endif
                    <dl class="flex flex-col gap-1.5 text-sm">
                        @foreach ($fields as $field)
                            <div class="flex gap-3">
                                <dt class="w-1/3 shrink-0 text-ink-2">{{ $field->getLabel() }}</dt>
                                <dd class="min-w-0 flex-1 break-words">
                                    @if ($loop->first && $url)
                                        <a href="{{ $url }}" class="font-medium text-ink hover:text-primary-text">@include('fastleo::resources.cell', ['inList' => true])</a>
                                    @else
                                        @include('fastleo::resources.cell', ['inList' => true])
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-2.5 flex items-center gap-2 border-t border-line pt-2">
                        @if ($selectable)
                            <input type="checkbox" class="size-[22px] rounded-[6px] accent-primary" value="{{ $record->getKey() }}" x-model="selected" aria-label="{{ __('fastleo::actions.select_record') }}">
                        @endif
                        @if ($manual)
                            @include('fastleo::resources.order-controls', ['handle' => false, 'first' => ! $tree && $loop->first && $records->onFirstPage(), 'last' => ! $tree && $loop->last && ! $records->hasMorePages()])
                        @endif
                        <div class="flex-1">{!! $renderActions($record, \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path'])) !!}</div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="md:hidden">
        @include('fastleo::partials.pagination', ['paginator' => $records, 'all' => $list->all, 'bordered' => false])
    </div>
    </div>
</x-fastleo::layouts.app>
