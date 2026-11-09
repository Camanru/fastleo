{{-- Создание ($record = null) и редактирование записи. --}}
@php
    $slug = $resource::slug();
    // Форма в окне (iframe) связанной таблицы: без меню и шапки, только «Сохранить»; связанных таблиц нет.
    $framed = $framed ?? false;
    $editing = $record !== null;
    $title = __($editing ? 'fastleo::resources.edit_title' : 'fastleo::resources.create_title', ['name' => $resource::singular()]);
    // Привязывает связанные таблицы (в том числе из вкладок): без этого вкладка с таблицей не знает, можно ли её показать.
    $editing && $resource->listRelations();
    $items = $resource->schema()->items();
    $asideItems = array_values(array_filter($items, fn ($item) => $item instanceof \Fastleo\Layout\Aside));
    $mainItems = array_values(array_filter($items, fn ($item) => ! $item instanceof \Fastleo\Layout\Aside));
    // Раскладка вкладок с таблицами связанных записей: только её событие fastleo-tab прячет «Сохранить» и Aside.
    $tabsGroup = null;
    foreach ($items as $item) {
        if ($item instanceof \Fastleo\Layout\Tabs && array_filter($item->visibleTabs($record), fn ($tab) => $tab->isTable()) !== []) {
            $tabsGroup = spl_object_id($item);

            break;
        }
    }
    $siteUrl = $editing ? $resource->url($record) : null;
    $canDelete = $editing && \Fastleo\Support\Authorization::allows('delete', $resource, $record);
    $recordActions = $editing ? array_filter($resource->actionsFor(false, false), fn ($action) => $action->allows($record) && $action->visibleFor($record)) : [];
    $recordTitle = $editing ? $resource->title($record) : '';
    $updatedAt = $editing && $record->getAttribute('updated_at') instanceof \Carbon\CarbonInterface ? $record->getAttribute('updated_at') : null;
@endphp
<x-dynamic-component
    :component="$framed ? 'fastleo::layouts.frame' : 'fastleo::layouts.app'"
    :picker="$framed"
    :closable="$framed"
    :title="$title"
    :heading="$recordTitle !== '' ? $recordTitle : null"
    :description="$updatedAt ? __('fastleo::resources.updated_at', ['time' => $updatedAt->timezone(\Fastleo\Fastleo::timezone())->format('d.m.Y H:i')]) : null"
    :back="['url' => $returnUrl ?? route('fastleo.resources.index', $slug), 'label' => ($returnUrl ?? null) ? __('fastleo::resources.back') : $resource::label()]"
    :crumbs="array_values(array_filter([$resource::group() !== null ? ['label' => $resource::group()] : null, ['label' => $resource::label(), 'url' => route('fastleo.resources.index', $slug)], ['label' => $recordTitle !== '' ? $recordTitle : $title]]))"
>
    <x-slot:badge>
        <span x-data x-cloak x-show="$store.fastleoForm.dirty" class="inline-flex h-6 items-center gap-1.5 rounded-full bg-warning-soft px-2.5 text-xs font-[550] text-warning" data-dirty-badge>
            <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
            {{ __('fastleo::resources.dirty') }}
        </span>
    </x-slot:badge>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2" x-data="fastleoActions({ open: @js($recordActions !== [] ? session('fastleo.action') : null), openIds: @js(array_values(array_filter((array) old('ids', []), 'is_string'))) })">
            @if ($recordActions !== [])
                @include('fastleo::actions.forms', ['actions' => $recordActions])
            @endif
            @include('fastleo::actions.buttons')
            @if ($siteUrl)
                <x-fastleo::button variant="ghost" icon="external-link" :href="$siteUrl" target="_blank" rel="noopener">
                    {{ __('fastleo::resources.open_on_site') }}
                </x-fastleo::button>
            @endif
            @if ($editing && ! $framed)
                @foreach ($resource->addButtons($record) as $button)
                    <x-fastleo::button variant="primary" icon="plus" :href="$button['url']">{{ $button['label'] }}</x-fastleo::button>
                @endforeach
            @endif
            @if ($canDelete)
                <x-fastleo::action-form
                    :action="route('fastleo.resources.destroy', [$slug, $record->getKey()])"
                    method="DELETE"
                    :confirm="$resource->deleteWarning($record) ?? __($resource::usesSoftDeletes() ? 'fastleo::resources.confirm_delete' : 'fastleo::resources.confirm_delete_forever')"
                >
                    <x-fastleo::button type="submit" variant="danger-soft" icon="trash-2">
                        {{ __('fastleo::resources.delete') }}
                    </x-fastleo::button>
                </x-fastleo::action-form>
            @endif
        </div>
    </x-slot:actions>

    <x-fastleo::flash />

    @if ($framed)
        <h1 class="mb-3 pr-12 text-lg font-semibold tracking-[-0.01em]">{{ $recordTitle !== '' ? $recordTitle : $title }}</h1>
    @endif

    @if ($editing && ! $framed && ($summaryView = $resource->summaryView()) !== null)
        {{-- Карточка записи над формой и вкладками: только чтение (summaryView() раздела). --}}
        <div class="mb-4">
            @include($summaryView, ['record' => $record, 'resource' => $resource])
        </div>
    @endif

    @php
        $hasSide = $editing && ! $framed && $resource->hasSideColumn($record);
    @endphp
    <div @class(['grid items-start gap-5', 'lg:grid-cols-[minmax(0,1fr)_24rem]' => $hasSide])>
        <div class="flex min-w-0 flex-col gap-5">
            <form
                method="POST"
                action="{{ $editing ? route('fastleo.resources.update', [$slug, $record->getKey()]) : route('fastleo.resources.store', $slug) }}"
                x-data="fastleoForm"
                data-unsaved="{{ __('fastleo::resources.unsaved') }}"
                @if ($errors->any()) data-dirty @endif
                novalidate
                class="flex flex-col gap-5"
            >
                @csrf
                @if ($editing)
                    @method('PUT')
                @endif
                {{-- Метки начала и конца формы: если PHP обрежет длинную форму по max_input_vars, конец не придёт и сохранение откажет. --}}
                <input type="hidden" name="_fl_begin" value="1">
                @if ($returnUrl ?? null)
                    <input type="hidden" name="_return" value="{{ $returnUrl }}">
                @endif
                @if ($framed)
                    <input type="hidden" name="_frame" value="1">
                @endif

                @if ($errors->any())
                    <div role="alert" class="flex gap-3 rounded-card bg-danger-soft px-4 py-3.5 text-danger">
                        <x-fastleo::icon name="circle-alert" class="mt-px size-[18px] shrink-0" />
                        <span class="text-base font-semibold">{{ __('fastleo::resources.errors_title') }}</span>
                    </div>
                @endif

                <div class="flex flex-wrap items-start gap-4">
                    {{-- Рядом боковая колонка карточки (история, таблицы aside): форме остаётся меньше места, и Aside не должен уезжать под неё. --}}
                    <div @class(['flex min-w-0 flex-col gap-4', 'flex-[999_1_520px]' => ! $hasSide, 'flex-[999_1_320px]' => $hasSide])>
                        @include('fastleo::form.cards', ['items' => $mainItems, 'record' => $record])
                    </div>
                    @if ($asideItems !== [])
                        {{-- На вкладке с таблицей связанных записей поля Aside прячутся вместе с кнопкой «Сохранить»: с неё форму не сохранить. --}}
                        <div x-data="{ visible: true }" x-show="visible" @fastleo-tab.window="if ($event.detail.group === {{ $tabsGroup ?? 'null' }}) visible = ! $event.detail.table" @class(['flex min-w-0 flex-col gap-4 lg:max-w-sm', 'flex-[1_1_300px]' => ! $hasSide, 'flex-[1_1_240px]' => $hasSide])>
                            @include('fastleo::form.items', ['items' => $asideItems, 'record' => $record])
                        </div>
                    @endif
                </div>

                <div x-data="{ visible: true }" x-show="visible" @fastleo-tab.window="if ($event.detail.group === {{ $tabsGroup ?? 'null' }}) visible = ! $event.detail.table" class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-t border-line bg-glass px-4 py-3 backdrop-blur-[18px] backdrop-saturate-[1.8] lg:-mx-8 lg:px-8">
                    <x-fastleo::button type="submit" variant="primary" icon="save" name="_after" value="edit" data-save title="{{ __('fastleo::resources.save_hint') }}">
                        {{ __('fastleo::resources.save') }}
                    </x-fastleo::button>
                    @unless ($framed)
                        <x-fastleo::button type="submit" name="_after" value="index">
                            {{ __('fastleo::resources.save_and_exit') }}
                        </x-fastleo::button>
                    @endunless
                    @unless ($editing || ($returnUrl ?? null) || $framed)
                        <x-fastleo::button type="submit" name="_after" value="create">
                            {{ __('fastleo::resources.save_and_create') }}
                        </x-fastleo::button>
                    @endunless
                    <span class="ml-auto hidden text-xs text-ink-2 sm:inline" data-mac="{{ __('fastleo::resources.save_hint_mac') }}">{{ __('fastleo::resources.save_hint') }}</span>
                </div>
                <input type="hidden" name="_fl_end" value="1">
            </form>
            @if ($editing && ! $framed)
                @include('fastleo::resources.related-sections', ['only' => 'main'])
                @include('fastleo::resources.related-tabs')
            @endif
        </div>
        @if ($hasSide)
            <aside class="flex min-w-0 flex-col gap-5" data-card-aside>
                @include('fastleo::resources.related-sections', ['only' => 'aside'])
            </aside>
        @endif
    </div>
</x-dynamic-component>
