{{-- Дашборд: приветствие и сетка виджетов. $widgets — список [widget, data]. --}}
<x-fastleo::layouts.app :title="__('fastleo::dashboard.title')" :description="__('fastleo::dashboard.welcome', ['name' => auth()->user()?->getAttribute('name')])">

    <x-fastleo::flash />

    <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-4" data-dashboard>
        @foreach ($widgets as $item)
            @php($span = match ($item['widget']->getSpan()) {
                2 => 'md:col-span-2 xl:col-span-2',
                3 => 'md:col-span-2 xl:col-span-3',
                4 => 'md:col-span-2 xl:col-span-4',
                default => '',
            })
            <x-fastleo::card :title="$item['widget']->getTitle()" :class="$span" data-widget="{{ class_basename($item['widget']) }}">
                @if ($item['data']['failed'])
                    <p class="text-sm text-danger" role="alert" data-widget-failed>{{ __('fastleo::dashboard.failed') }}</p>
                @else
                    @include($item['widget']->view(), $item['data'])
                @endif
            </x-fastleo::card>
        @endforeach
    </div>

    @if ($hasChart)
        @push('fastleo-scripts')
            <script defer src="{{ \Fastleo\Support\Assets::url('fastleo-chart.js') }}"></script>
        @endpush
    @endif
</x-fastleo::layouts.app>
