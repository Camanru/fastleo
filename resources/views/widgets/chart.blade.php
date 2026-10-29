{{-- График Chart.js. $chart (type, label, labels, values), $empty. --}}
@if ($empty)
    <p class="text-sm text-ink-2">{{ __('fastleo::dashboard.chart_empty') }}</p>
@else
    <div class="h-56" role="img" aria-label="{{ $chart['label'] }}">
        <canvas data-chart="{{ json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}"></canvas>
    </div>
@endif
