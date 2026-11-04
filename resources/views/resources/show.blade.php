{{-- Просмотр записи: подписи и значения полей. $framed — карточка для окна просмотра (без меню, кнопок и связанных таблиц). --}}
@php
    $framed = $framed ?? false;
    $slug = $resource::slug();
    $siteUrl = $resource->url($record);
    $trashed = method_exists($record, 'trashed') && $record->trashed();
    $recordActions = $trashed || $framed ? [] : array_filter($resource->actionsFor(false, false), fn ($action) => $action->allows($record) && $action->visibleFor($record));
@endphp
@php
    $recordTitle = $resource->title($record);
    $updatedAt = $record->getAttribute('updated_at') instanceof \Carbon\CarbonInterface ? $record->getAttribute('updated_at') : null;
    $listUrl = route('fastleo.resources.index', $trashed ? ['resource' => $slug, 'trashed' => 1] : $slug);
@endphp
<x-dynamic-component
    :component="$framed ? 'fastleo::layouts.card' : 'fastleo::layouts.app'"
    :title="__('fastleo::resources.show_title', ['name' => $resource::singular()])"
    :heading="$recordTitle !== '' ? $recordTitle : null"
    :description="$updatedAt ? __('fastleo::resources.updated_at', ['time' => $updatedAt->timezone(\Fastleo\Fastleo::timezone())->format('d.m.Y H:i')]) : null"
    :back="['url' => $returnUrl ?? $listUrl, 'label' => ($returnUrl ?? null) ? __('fastleo::resources.back') : $resource::label()]"
    :crumbs="array_values(array_filter([$resource::group() !== null ? ['label' => $resource::group()] : null, ['label' => $resource::label(), 'url' => $listUrl], ['label' => $recordTitle !== '' ? $recordTitle : __('fastleo::resources.show_title', ['name' => $resource::singular()])]]))"
>
    @unless ($framed)
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
            @if (! $trashed && \Fastleo\Support\Authorization::allows('update', $resource, $record))
                <x-fastleo::button variant="primary" icon="pencil" :href="route('fastleo.resources.edit', [$slug, $record->getKey()])">
                    {{ __('fastleo::resources.edit') }}
                </x-fastleo::button>
            @endif
        </div>
    </x-slot:actions>
    @endunless

    <x-fastleo::flash />

    @if ($framed)
        <h1 class="mb-3 pr-12 text-lg font-semibold tracking-[-0.01em]">{{ $recordTitle !== '' ? $recordTitle : __('fastleo::resources.show_title', ['name' => $resource::singular()]) }}</h1>
    @endif

    @php
        $hasSide = ! $framed && $resource->hasSideColumn($record);
    @endphp
    <div @class(['grid items-start gap-5', 'lg:grid-cols-[minmax(0,1fr)_24rem]' => $hasSide])>
        <div class="flex min-w-0 flex-col gap-5">
        @php
            $longTypes = [\Fastleo\Fields\RichText::class, \Fastleo\Fields\Markdown::class, \Fastleo\Fields\Textarea::class, \Fastleo\Fields\Code::class, \Fastleo\Fields\Blocks::class, \Fastleo\Fields\Repeater::class, \Fastleo\Fields\KeyValue::class, \Fastleo\Fields\Gallery::class];
            $isLong = fn ($field): bool => count(array_filter($longTypes, fn ($type) => $field instanceof $type)) > 0;
            $shownFields = $resource->showFields($record);
            $shortFields = array_filter($shownFields, fn ($field) => ! $isLong($field));
            $longFields = array_filter($shownFields, $isLong);
        @endphp

        @if ($shortFields !== [])
            <x-fastleo::card class="!gap-0 !p-0">
                <h2 class="px-5 pt-4 pb-2.5 text-md font-semibold tracking-[-0.01em]">{{ __('fastleo::resources.details') }}</h2>
                <dl class="grid grid-cols-1 gap-x-6 px-5 pb-2.5 md:grid-cols-2">
                    @foreach ($shortFields as $field)
                        <div class="flex min-w-0 flex-col gap-0.5 border-t border-line py-2.5 first:border-t-0 md:[&:nth-child(2)]:border-t-0">
                            <dt class="text-xs font-medium text-ink-2">{{ $field->getLabel() }}</dt>
                            <dd class="min-w-0 text-base break-words whitespace-pre-line">@include('fastleo::resources.cell')</dd>
                        </div>
                    @endforeach
                </dl>
            </x-fastleo::card>
        @endif

        @foreach ($longFields as $field)
            <x-fastleo::card :title="$field->getLabel()">
                <div class="max-w-[68ch] min-w-0 text-base leading-[1.6] break-words whitespace-pre-line">@include('fastleo::resources.cell')</div>
            </x-fastleo::card>
        @endforeach
            @unless ($framed)
                @include('fastleo::resources.related-sections', ['only' => 'main', 'withTab' => true])
            @endunless
        </div>
        @if ($hasSide)
            <aside class="flex min-w-0 flex-col gap-5" data-card-aside>
                @include('fastleo::resources.related-sections', ['only' => 'aside'])
            </aside>
        @endif
    </div>
</x-dynamic-component>
