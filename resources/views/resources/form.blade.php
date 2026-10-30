{{-- Создание ($record = null) и редактирование записи. --}}
@php
    $slug = $resource::slug();
    $editing = $record !== null;
    $title = __($editing ? 'fastleo::resources.edit_title' : 'fastleo::resources.create_title', ['name' => $resource::singular()]);
    $items = $resource->schema()->items();
    $asideItems = array_values(array_filter($items, fn ($item) => $item instanceof \Fastleo\Layout\Aside));
    $mainItems = array_values(array_filter($items, fn ($item) => ! $item instanceof \Fastleo\Layout\Aside));
    $siteUrl = $editing ? $resource->url($record) : null;
    $canDelete = $editing && \Fastleo\Support\Authorization::allows('delete', $resource, $record);
    $recordActions = $editing ? array_filter($resource->actionsFor(false, false), fn ($action) => $action->allows($record) && $action->visibleFor($record)) : [];
    $titleField = $resource->listFields()[0] ?? null;
    $recordTitle = $editing && $titleField !== null ? trim((string) $titleField->display($titleField->shownRecord($record))) : '';
    $updatedAt = $editing && $record->getAttribute('updated_at') instanceof \Carbon\CarbonInterface ? $record->getAttribute('updated_at') : null;
@endphp
<x-fastleo::layouts.app
    :title="$title"
    :heading="$recordTitle !== '' ? $recordTitle : null"
    :description="$updatedAt ? __('fastleo::resources.updated_at', ['time' => $updatedAt->timezone(\Fastleo\Fastleo::timezone())->format('d.m.Y H:i')]) : null"
    :back="['url' => route('fastleo.resources.index', $slug), 'label' => $resource::label()]"
    :crumbs="array_values(array_filter([$resource::group() !== null ? ['label' => $resource::group()] : null, ['label' => $resource::label(), 'url' => route('fastleo.resources.index', $slug)], ['label' => $recordTitle !== '' ? $recordTitle : $title]]))"
>
    <x-slot:badge>
        <span x-data x-cloak x-show="$store.fastleoForm.dirty" class="inline-flex h-6 items-center gap-1.5 rounded-full bg-warning-soft px-2.5 text-xs font-[550] text-warning" data-dirty-badge>
            <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
            {{ __('fastleo::resources.dirty') }}
        </span>
    </x-slot:badge>
    <div class="flex flex-wrap items-center gap-2" x-data="fastleoActions({ open: @js($recordActions !== [] ? session('fastleo.action') : null), openIds: @js(array_values(array_filter((array) old('ids', []), 'is_string'))) })">
        @if ($recordActions !== [])
            @include('fastleo::actions.forms', ['actions' => $recordActions])
        @endif
        <div class="flex-1"></div>
        @include('fastleo::actions.buttons')
        @if ($editing && $resource::logsActivity() && \Fastleo\Activity\ActivityRecorder::isEnabled() && \Fastleo\Support\Authorization::allows('viewAny', $resource))
            <x-fastleo::button variant="ghost" icon="history" :href="route('fastleo.resources.history', [$slug, $record->getKey()])">{{ __('fastleo::activity.history') }}</x-fastleo::button>
        @endif
        @if ($siteUrl)
            <x-fastleo::button variant="ghost" icon="external-link" :href="$siteUrl" target="_blank" rel="noopener">
                {{ __('fastleo::resources.open_on_site') }}
            </x-fastleo::button>
        @endif
        @if ($canDelete)
            <x-fastleo::action-form
                :action="route('fastleo.resources.destroy', [$slug, $record->getKey()])"
                method="DELETE"
                :confirm="__($resource::usesSoftDeletes() ? 'fastleo::resources.confirm_delete' : 'fastleo::resources.confirm_delete_forever')"
            >
                <x-fastleo::button type="submit" variant="danger-soft" icon="trash-2">
                    {{ __('fastleo::resources.delete') }}
                </x-fastleo::button>
            </x-fastleo::action-form>
        @endif
    </div>

    <x-fastleo::flash />

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

        @if ($errors->any())
            <div role="alert" class="flex gap-3 rounded-card bg-danger-soft px-4 py-3.5 text-danger">
                <x-fastleo::icon name="circle-alert" class="mt-px size-[18px] shrink-0" />
                <span class="text-base font-semibold">{{ __('fastleo::resources.errors_title') }}</span>
            </div>
        @endif

        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-[999_1_520px] flex-col gap-4">
                @include('fastleo::form.cards', ['items' => $mainItems, 'record' => $record])
            </div>
            @if ($asideItems !== [])
                <div class="flex min-w-0 flex-[1_1_300px] flex-col gap-4 lg:max-w-sm">
                    @include('fastleo::form.items', ['items' => $asideItems, 'record' => $record])
                </div>
            @endif
        </div>

        <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-t border-line bg-glass px-4 py-3 backdrop-blur-[18px] backdrop-saturate-[1.8] lg:-mx-8 lg:px-8">
            <x-fastleo::button type="submit" variant="primary" icon="save" name="_after" value="edit" data-save title="{{ __('fastleo::resources.save_hint') }}">
                {{ __('fastleo::resources.save') }}
            </x-fastleo::button>
            <x-fastleo::button type="submit" name="_after" value="index">
                {{ __('fastleo::resources.save_and_exit') }}
            </x-fastleo::button>
            @unless ($editing)
                <x-fastleo::button type="submit" name="_after" value="create">
                    {{ __('fastleo::resources.save_and_create') }}
                </x-fastleo::button>
            @endunless
            <span class="ml-auto hidden text-xs text-ink-2 sm:inline" data-mac="{{ __('fastleo::resources.save_hint_mac') }}">{{ __('fastleo::resources.save_hint') }}</span>
        </div>
        <input type="hidden" name="_fl_end" value="1">
    </form>
</x-fastleo::layouts.app>
