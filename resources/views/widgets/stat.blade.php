{{-- Плитка с числом. $value, $description, $icon, $url. --}}
@php($tag = $url ? 'a' : 'div')
<{{ $tag }} @if ($url) href="{{ $url }}" @endif @class(['flex items-center justify-between gap-3', '-m-2 rounded-[10px] p-2 hover:bg-hover' => $url])>
    <span class="min-w-0">
        <span class="block truncate text-3xl font-[650] tracking-[-0.025em] tabular-nums" data-stat-value>{{ $value }}</span>
        @if ($description)
            <span class="block truncate text-sm text-ink-2">{{ $description }}</span>
        @endif
    </span>
    <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-sunken text-ink-2">
        <x-fastleo::icon :name="$icon" class="size-[18px]" />
    </span>
</{{ $tag }}>
