{{-- Связанная таблица записей другого раздела (кусок страницы, без рамки админки): заголовок, поиск, «Добавить»,
     таблица со столбцами списка дочернего раздела, кнопки строки и страницы. $resource — дочерний раздел,
     $parent/$parentRecord — родитель, $related, $list, $records, $returnUrl, $canAdd, $openAction/$openIds — окно мини-формы,
     которое надо открыть сразу (форма не прошла проверку).
     Ссылки в [data-related-nav] и форма поиска подгружают кусок заново (fastleoRelated), кнопки строки — обычные формы. --}}
@php
    $slug = $resource::slug();
    // Столбец связи с родителем одинаков в каждой строке — в таблице его нет; набор столбцов можно сузить: Related::columns().
    $fields = $related->tableFields();
    $memo = [];
    $allows = function (string $ability, $record = null) use ($resource, &$memo): bool {
        return $memo[$ability.'|'.($record?->getKey() ?? '')] ??= \Fastleo\Support\Authorization::allows($ability, $resource, $record);
    };
    $actionAllows = function ($action, $record) use (&$memo): bool {
        return $memo['action|'.spl_object_id($action).'|'.$record->getKey()] ??= $action->allows($record);
    };
    $expandable = $related->isExpandable();
    $columns = count($fields) + 1 + ($expandable ? 1 : 0);
    $singleActions = $resource->actionsFor(false, false);
    $formActions = array_filter($resource->listActions(), fn ($action) => ! $action->isForTrash());
    $tree = null;
    $sortUrl = function (string $name) use ($list): string {
        $direction = $list->sort === $name && $list->direction === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $name, 'direction' => $direction, 'page' => null]);
    };
    $recordUrl = function ($record) use ($allows, $slug, $returnUrl): ?string {
        if ($allows('update', $record)) {
            return route('fastleo.resources.edit', [$slug, $record->getKey(), 'return' => $returnUrl]);
        }

        return $allows('view', $record) ? route('fastleo.resources.show', [$slug, $record->getKey(), 'return' => $returnUrl]) : null;
    };
    $createUrl = route('fastleo.resources.create', ['resource' => $slug, 'prefill' => [$related->foreignKey() => $related->foreignValue($parentRecord)], 'return' => $returnUrl]);
@endphp
<div x-data="fastleoActions({ ids: @js($records->map(fn ($record) => (string) $record->getKey())->values()->all()), open: @js($openAction), openIds: @js($openIds) })" class="flex flex-col gap-3" data-related-table="{{ $related->name }}">
    @include('fastleo::actions.forms', ['actions' => $formActions, 'relation' => $related->name, 'relationParent' => $parentRecord->getKey()])
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-lg font-semibold tracking-[-0.01em]">{{ $related->getLabel() }}</h2>
        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-sunken px-1.5 text-xs font-medium text-ink-2 tabular-nums">{{ $records->total() }}</span>
        <div class="flex-1"></div>
        @if ($list->hasSearch())
            <form method="GET" action="{{ route('fastleo.resources.related', [$parent::slug(), $parentRecord->getKey(), $related->name]) }}" role="search" data-related-search class="relative w-full min-w-0 sm:w-64">
                <input type="hidden" name="return" value="{{ $returnUrl }}">
                @if ($list->all)
                    <input type="hidden" name="per_page" value="all">
                @elseif ($list->size !== null)
                    <input type="hidden" name="per_page" value="{{ $list->size }}">
                @endif
                @if ($list->sort !== null)
                    <input type="hidden" name="sort" value="{{ $list->sort }}">
                    <input type="hidden" name="direction" value="{{ $list->direction }}">
                @endif
                <span class="sr-only">{{ __('fastleo::resources.search') }}</span>
                <x-fastleo::icon name="search" class="pointer-events-none absolute top-1/2 left-[11px] size-4 -translate-y-1/2 text-ink-3" />
                <input type="search" name="q" value="{{ $list->search }}" placeholder="{{ __('fastleo::resources.search_placeholder') }}" class="fastleo-input h-9 pl-9" aria-label="{{ __('fastleo::resources.search') }}">
            </form>
        @endif
        @if ($canAdd)
            <x-fastleo::button variant="primary" size="sm" icon="plus" :href="$createUrl">{{ $related->getAddLabel() }}</x-fastleo::button>
        @endif
    </div>

    @if ($records->isEmpty())
        <div class="rounded-card border border-line bg-surface px-6 py-8 text-center text-sm text-ink-2">
            {{ __($list->isNarrowed() ? 'fastleo::resources.nothing_found' : 'fastleo::resources.related_empty') }}
        </div>
    @else
        <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
            <div class="relative overflow-x-auto">
                <table class="w-full border-separate border-spacing-0 text-left text-base">
                    <thead data-related-nav>
                        <tr class="[&>th]:h-10 [&>th]:border-b [&>th]:border-line [&>th]:bg-surface-2 [&>th]:px-3 [&>th]:text-xs [&>th]:font-[550] [&>th]:whitespace-nowrap [&>th]:text-ink-2">
                            @if ($expandable)
                                <th scope="col" class="w-px"><span class="sr-only">{{ __('fastleo::resources.expand') }}</span></th>
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
                            <th scope="col" class="sticky right-0 z-[1]"><span class="sr-only">{{ __('fastleo::resources.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            @php($url = $recordUrl($record))
                            @php($tone = $resource->rowToneFor($record))
                            <tr class="group/row hover:bg-hover [&:last-child>td]:border-b-0 [&>td]:border-b [&>td]:border-line [&>td]:px-3 [&>td]:py-2.5 compact:[&>td]:py-1.5" data-record="{{ $record->getKey() }}" @if ($tone) data-tone="{{ $tone }}" @endif @if ($resource->isUnread($record)) data-unread @endif>
                                @if ($expandable)
                                    <td class="w-px !pr-0">
                                        @if ($allows('view', $record))
                                            <button type="button" class="flex size-6 items-center justify-center rounded-[6px] text-ink-2 transition-colors hover:bg-hover hover:text-ink" data-expand aria-expanded="false" title="{{ __('fastleo::resources.expand') }}">
                                                <x-fastleo::icon name="chevron-right" class="size-4 transition-transform group-aria-expanded/row:rotate-90" />
                                                <span class="sr-only">{{ __('fastleo::resources.expand') }}</span>
                                            </button>
                                        @endif
                                    </td>
                                @endif
                                @foreach ($fields as $field)
                                    <td data-field="{{ $field->name }}" @class(['max-w-xs truncate', 'text-right tabular-nums' => $field instanceof \Fastleo\Fields\Number])>
                                        @if ($loop->first && $url && ! ($field instanceof \Fastleo\Fields\Checkbox && $field->isQuickToggle()) && ! $field->isInlineEditable())
                                            <a href="{{ $url }}" class="font-medium text-ink hover:text-primary-text">@include('fastleo::resources.cell', ['inList' => true])</a>
                                        @else
                                            @include('fastleo::resources.cell', ['inList' => true])
                                        @endif
                                    </td>
                                @endforeach
                                <td class="sticky right-0 z-[1] w-px bg-surface has-[[aria-expanded=true]]:z-[2] whitespace-nowrap group-hover/row:bg-hover">@include('fastleo::resources.row-actions', ['childCount' => 0])</td>
                            </tr>
                            @if ($expandable && $allows('view', $record))
                                <tr data-expansion data-url="{{ route('fastleo.resources.expand', [$slug, $record->getKey(), 'return' => $returnUrl]) }}" hidden>
                                    <td colspan="{{ $columns }}" class="!border-b !border-line !bg-surface-2 !p-3">
                                        <div data-expansion-body></div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div data-related-nav>
                @include('fastleo::partials.pagination', ['paginator' => $records, 'all' => $list->all, 'sizes' => $resource::perPageOptions(), 'inline' => true])
            </div>
        </div>
    @endif
    @if ($canAdd && $related->hasQuickAdd() && $related->quickAddFields() !== [])
        {{-- Быстрое добавление: поля дочернего раздела под таблицей, запись создаётся без перехода на другую страницу. --}}
        <form
            x-data="fastleoQuickAdd({ url: @js(route('fastleo.resources.related.store', [$parent::slug(), $parentRecord->getKey(), $related->name])), failed: @js(__('fastleo::resources.related_add_failed')) })"
            x-on:submit.prevent="submit($event)"
            class="fastleo-card @container flex flex-col gap-3 p-3"
            data-quick-add="{{ $related->name }}"
            novalidate
        >
            <div class="grid grid-cols-1 gap-3 @lg:grid-cols-2 @3xl:grid-cols-3">
                @include('fastleo::form.items', ['items' => $related->quickAddFields(), 'record' => null, 'namePrefix' => 'data', 'pathPrefix' => 'data', 'idPrefix' => 'quick-'.$related->name.'-'.$parentRecord->getKey().'-'])
            </div>
            <ul x-cloak x-show="errors.length > 0" class="flex flex-col gap-0.5 text-xs text-danger" role="alert">
                <template x-for="message in errors" :key="message"><li x-text="message"></li></template>
            </ul>
            <div class="flex justify-end">
                <x-fastleo::button type="submit" variant="primary" size="sm" icon="plus" x-bind:disabled="busy">{{ $related->getAddLabel() }}</x-fastleo::button>
            </div>
        </form>
    @endif
</div>
