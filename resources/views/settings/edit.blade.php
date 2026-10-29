{{-- Страница настроек. $settings, $record. --}}
@php($items = $settings->schema()->items())
<x-fastleo::layouts.app :title="$settings::label()">
    <x-fastleo::flash />

    <form
        method="POST"
        action="{{ route('fastleo.settings.update', $settings::slug()) }}"
        x-data="fastleoForm"
        data-unsaved="{{ __('fastleo::resources.unsaved') }}"
        @if ($errors->any()) data-dirty @endif
        novalidate
        class="flex flex-col gap-5"
    >
        @csrf
        @method('PUT')

        @if ($errors->any())
            <div role="alert" class="flex gap-3 rounded-card bg-danger-soft px-4 py-3.5 text-danger">
                <x-fastleo::icon name="circle-alert" class="mt-px size-[18px] shrink-0" />
                <span class="text-base font-semibold">{{ __('fastleo::resources.errors_title') }}</span>
            </div>
        @endif

        @include('fastleo::form.cards', ['items' => $items, 'record' => $record])

        <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-t border-line bg-glass px-4 py-3 backdrop-blur-[18px] backdrop-saturate-[1.8] lg:-mx-8 lg:px-8">
            <x-fastleo::button type="submit" variant="primary" icon="save" data-save title="{{ __('fastleo::resources.save_hint') }}">
                {{ __('fastleo::settings.save') }}
            </x-fastleo::button>
            <span x-cloak x-show="dirty" class="inline-flex h-6 items-center gap-1.5 rounded-full bg-warning-soft px-2.5 text-xs font-[550] text-warning" data-dirty-badge>
                <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                {{ __('fastleo::resources.dirty') }}
            </span>
            <span class="ml-auto hidden text-xs text-ink-2 sm:inline" data-mac="{{ __('fastleo::resources.save_hint_mac') }}">{{ __('fastleo::resources.save_hint') }}</span>
        </div>
    </form>

    @if ($settings->afterForm())
        @include($settings->afterForm(), ['settings' => $settings])
    @endif
</x-fastleo::layouts.app>
