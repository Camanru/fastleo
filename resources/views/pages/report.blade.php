{{-- Страница-отчёт (Page::filters() и Page::widgets()): панель фильтров и сетка виджетов.
     $page, $items (фильтры), $filters (значения), $widgets ([widget, data]), $hasChart. --}}
<x-fastleo::layouts.app :title="$page::label()">
    <x-fastleo::flash />

    @if ($items !== [])
        <form id="fastleo-report-filters" method="GET" action="{{ route('fastleo.pages.show', $page::slug()) }}" role="search" class="fastleo-card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4" data-report-filters>
            @foreach ($items as $filter)
                @include($filter->view(), ['filter' => $filter, 'value' => $filters->value($filter->name), 'form' => 'fastleo-report-filters'])
            @endforeach
        </form>
    @endif

    @if ($widgets === [])
        <p class="text-sm text-ink-2">{{ __('fastleo::reports.empty') }}</p>
    @else
        <div class="fastleo-dashboard" data-report data-columns="{{ $page::columns() }}">
            @foreach ($widgets as $item)
                <x-fastleo::card
                    :title="$item['widget']->getTitle()"
                    data-widget="{{ class_basename($item['widget']) }}"
                    data-cols="{{ min($item['widget']->getSpan(), $page::columns()) }}"
                    data-rows="{{ $item['widget']->getRows() }}"
                >
                    @if ($item['data']['failed'])
                        <p class="text-sm text-danger" role="alert" data-widget-failed>{{ __('fastleo::reports.failed') }}</p>
                    @else
                        @include($item['widget']->view(), $item['data'])
                    @endif
                </x-fastleo::card>
            @endforeach
        </div>
    @endif

    @if ($hasChart)
        @push('fastleo-scripts')
            <script defer src="{{ \Fastleo\Support\Assets::url('fastleo-chart.js') }}"></script>
        @endpush
    @endif
</x-fastleo::layouts.app>
