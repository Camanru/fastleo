@props(['tone' => 'neutral', 'dot' => true])
{{-- Бейдж статуса: <x-fastleo::badge tone="success">Опубликована</x-fastleo::badge>.
     Тона: success, warning, danger, info, neutral. Смысл всегда дублируется текстом. --}}
@php
    $classes = match ($tone) {
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'info' => 'bg-info-soft text-info',
        default => 'bg-sunken text-ink-2',
    };
@endphp
<span {{ $attributes->class(['inline-flex h-[22px] items-center gap-1.5 rounded-badge px-2 text-xs font-[550] whitespace-nowrap', $classes]) }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
