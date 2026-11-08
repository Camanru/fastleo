{{-- График Chart.js. $chart (type, label, labels, values), $json (те же данные строкой), $empty. --}}
@if ($empty)
    <p class="text-sm text-ink-2">{{ __('fastleo::dashboard.chart_empty') }}</p>
@else
    <div class="relative min-h-56 flex-1" role="img" aria-label="{{ $chart['label'] }}">
        <canvas class="absolute inset-0" data-chart="{{ $json }}"></canvas>
    </div>
@endif
