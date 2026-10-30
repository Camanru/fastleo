{{-- Дашборд: приветствие и сетка виджетов. $widgets — список [widget, key, cols, rows, hidden, data]. --}}
<x-fastleo::layouts.app :title="__('fastleo::dashboard.title')" :description="__('fastleo::dashboard.welcome', ['name' => auth()->user()?->getAttribute('name')])">

    <x-slot:actions></x-slot:actions>

    <x-fastleo::flash />

    <div x-data="fastleoDashboard({ url: @js(route('fastleo.dashboard.layout.save')) })">
        <template x-teleport="#fastleo-page-actions">
            <div class="flex flex-wrap items-center gap-2">
                <x-fastleo::button variant="ghost" icon="layout-grid" x-show="! editing" x-on:click="start()" data-dashboard-edit>{{ __('fastleo::dashboard.customize') }}</x-fastleo::button>

                <div x-show="editing" x-cloak class="flex flex-wrap items-center gap-2" data-dashboard-toolbar>
                    <p class="text-sm text-danger" role="alert" x-show="failed" x-cloak>{{ __('fastleo::dashboard.layout_failed') }}</p>
                    <x-fastleo::action-form :action="route('fastleo.dashboard.layout.reset')" method="DELETE" :confirm="__('fastleo::dashboard.layout_reset_confirm')" :button="__('fastleo::dashboard.layout_reset')" :danger="false">
                        <x-fastleo::button variant="ghost" icon="rotate-ccw" type="submit">{{ __('fastleo::dashboard.layout_reset') }}</x-fastleo::button>
                    </x-fastleo::action-form>
                    <x-fastleo::button x-on:click="cancel()" data-dashboard-cancel>{{ __('fastleo::dashboard.layout_cancel') }}</x-fastleo::button>
                    <x-fastleo::button variant="primary" icon="check" x-on:click="save()" x-bind:aria-busy="saving" data-dashboard-save>{{ __('fastleo::dashboard.layout_save') }}</x-fastleo::button>
                </div>
            </div>
        </template>

        <div class="fastleo-dashboard" x-ref="grid" x-bind:data-editing="editing ? '' : null" data-dashboard>
            @foreach ($widgets as $item)
                <x-fastleo::card
                    :title="$item['widget']->getTitle()"
                    class="group/widget data-[hidden=true]:opacity-60"
                    data-widget="{{ class_basename($item['widget']) }}"
                    data-key="{{ $item['key'] }}"
                    data-cols="{{ $item['cols'] }}"
                    data-rows="{{ $item['rows'] }}"
                    data-hidden="{{ $item['hidden'] ? 'true' : 'false' }}"
                >
                    <div x-show="editing" x-cloak class="order-first -mx-1 flex flex-wrap items-center gap-1 rounded-[10px] bg-sunken p-1" data-widget-bar>
                        <button type="button" class="flex size-8 cursor-grab items-center justify-center rounded-[8px] text-ink-2 hover:bg-hover active:cursor-grabbing" data-drag title="{{ __('fastleo::dashboard.layout_drag') }}" aria-label="{{ __('fastleo::dashboard.layout_drag') }}">
                            <x-fastleo::icon name="grip-vertical" class="size-4" />
                        </button>
                        <span class="flex items-center gap-0.5 text-ink-2">
                            <x-fastleo::icon name="move-horizontal" class="size-4" />
                            <button type="button" class="size-7 rounded-[8px] hover:bg-hover disabled:opacity-40" x-on:click="resize($el, 'cols', -1)" data-resize="cols-minus" aria-label="{{ __('fastleo::dashboard.layout_narrower') }}" title="{{ __('fastleo::dashboard.layout_narrower') }}">−</button>
                            <span class="w-4 text-center text-sm tabular-nums text-ink" data-size="cols">{{ $item['cols'] }}</span>
                            <button type="button" class="size-7 rounded-[8px] hover:bg-hover disabled:opacity-40" x-on:click="resize($el, 'cols', 1)" data-resize="cols-plus" aria-label="{{ __('fastleo::dashboard.layout_wider') }}" title="{{ __('fastleo::dashboard.layout_wider') }}">+</button>
                        </span>
                        <span class="flex items-center gap-0.5 text-ink-2">
                            <x-fastleo::icon name="move-vertical" class="size-4" />
                            <button type="button" class="size-7 rounded-[8px] hover:bg-hover disabled:opacity-40" x-on:click="resize($el, 'rows', -1)" data-resize="rows-minus" aria-label="{{ __('fastleo::dashboard.layout_shorter') }}" title="{{ __('fastleo::dashboard.layout_shorter') }}">−</button>
                            <span class="w-4 text-center text-sm tabular-nums text-ink" data-size="rows">{{ $item['rows'] }}</span>
                            <button type="button" class="size-7 rounded-[8px] hover:bg-hover disabled:opacity-40" x-on:click="resize($el, 'rows', 1)" data-resize="rows-plus" aria-label="{{ __('fastleo::dashboard.layout_taller') }}" title="{{ __('fastleo::dashboard.layout_taller') }}">+</button>
                        </span>
                        <button type="button" class="ml-auto flex h-8 items-center gap-1.5 rounded-[8px] px-2 text-sm font-medium text-ink-2 hover:bg-hover hover:text-ink" x-on:click="toggleHidden($el)" data-toggle-hidden>
                            <span class="flex items-center gap-1.5 group-data-[hidden=true]/widget:hidden"><x-fastleo::icon name="eye-off" class="size-4" />{{ __('fastleo::dashboard.layout_hide') }}</span>
                            <span class="hidden items-center gap-1.5 group-data-[hidden=true]/widget:flex"><x-fastleo::icon name="eye" class="size-4" />{{ __('fastleo::dashboard.layout_show') }}</span>
                        </button>
                    </div>

                    @if ($item['hidden'])
                        <p class="text-sm text-ink-2" data-widget-hidden>{{ __('fastleo::dashboard.layout_hidden_note') }}</p>
                    @elseif ($item['data']['failed'])
                        <p class="text-sm text-danger" role="alert" data-widget-failed>{{ __('fastleo::dashboard.failed') }}</p>
                    @else
                        @include($item['widget']->view(), $item['data'])
                    @endif
                </x-fastleo::card>
            @endforeach
        </div>
    </div>

    @if ($hasChart)
        @push('fastleo-scripts')
            <script defer src="{{ \Fastleo\Support\Assets::url('fastleo-chart.js') }}"></script>
        @endpush
    @endif
</x-fastleo::layouts.app>
