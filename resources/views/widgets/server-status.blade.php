{{-- Состояние сервера. $sections, $attention, $cacheSize. --}}
<div class="flex flex-wrap items-center gap-3">
    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold', 'bg-warning-soft text-warning  ' => $attention > 0, 'bg-success-soft text-success  ' => $attention === 0]) data-server-attention>
        {{ $attention > 0 ? __('fastleo::dashboard.attention', ['count' => $attention]) : __('fastleo::dashboard.all_fine') }}
    </span>
    <div class="flex-1"></div>
    @if (\Fastleo\Support\CacheCleaner::canClear())
        <x-fastleo::action-form :action="route('fastleo.dashboard.clear-cache')" :confirm="__('fastleo::dashboard.cache_clear_confirm')" :button="__('fastleo::dashboard.cache_clear')" :danger="false">
            <x-fastleo::button type="submit" icon="brush-cleaning" data-clear-cache>
                {{ __('fastleo::dashboard.cache_clear') }} <span class="font-normal text-ink-2">({{ __('fastleo::dashboard.cache_size', ['size' => $cacheSize]) }})</span>
            </x-fastleo::button>
        </x-fastleo::action-form>
    @endif
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    @foreach ($sections as $section)
        <div>
            <h3 class="mb-2 text-[11px] font-semibold tracking-[.04em] text-ink-3 uppercase">{{ $section['title'] }}</h3>
            <dl class="flex flex-col gap-2 text-sm">
                @foreach ($section['rows'] as $row)
                    <div data-server-row @if ($row['status']) data-status="{{ $row['status'] }}" @endif>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-ink-2">{{ $row['title'] }}</dt>
                            <dd @class(['text-right font-medium break-all', 'text-warning ' => $row['status'] === 'warning', 'text-danger ' => $row['status'] === 'danger'])>
                                @if ($row['status'] === 'warning')<span class="sr-only">{{ __('fastleo::dashboard.server.warning_label') }}</span>@endif
                                @if ($row['status'] === 'danger')<span class="sr-only">{{ __('fastleo::dashboard.server.danger_label') }}</span>@endif
                                {{ $row['value'] }}
                            </dd>
                        </div>
                        @if ($row['hint'])
                            <p class="mt-0.5 text-xs text-ink-2">{{ $row['hint'] }}</p>
                        @endif
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach
</div>
