@props(['variant' => 'secondary', 'href' => null, 'icon' => null, 'size' => 'md'])
{{-- Кнопка или ссылка-кнопка: <x-fastleo::button variant="primary" icon="plus" :href="...">Создать</x-fastleo::button>
     Варианты: primary, secondary, ghost, danger, danger-soft, icon и dashed (добавить пункт, пунктирная рамка во всю ширину), icon-danger (только иконка, подпись — в sr-only). Размеры: md, sm, lg (вход). --}}
@php
    $classes = match ($variant) {
        'primary' => 'bg-primary font-[550] text-white shadow-[inset_0_1px_0_rgb(255_255_255/.16),0_1px_2px_rgb(0_0_0/.12)] hover:bg-primary-hover',
        'danger' => 'bg-danger-solid font-[550] text-white hover:brightness-95',
        'danger-soft' => 'bg-danger-soft font-medium text-danger hover:brightness-95',
        'ghost' => 'font-medium text-ink-2 hover:bg-hover hover:text-ink',
        'dashed' => 'w-full border border-dashed border-line-strong font-medium text-ink-2 hover:bg-hover hover:text-ink',
        'icon' => 'size-8 justify-center rounded-[8px] text-ink-2 hover:bg-hover hover:text-ink',
        'icon-danger' => 'size-8 justify-center rounded-[8px] text-ink-2 hover:bg-danger-soft hover:text-danger',
        default => 'border border-line-strong bg-surface font-medium text-ink hover:bg-surface-2',
    };
    $dimensions = match (true) {
        str_starts_with($variant, 'icon') => '',
        $size === 'sm' => 'h-[30px] gap-1.5 rounded-[7px] px-2.5 text-sm',
        $size === 'lg' => 'h-10 gap-2 rounded-[10px] px-4 text-md',
        default => 'h-9 gap-2 rounded-btn px-3.5 text-base',
    };
    $classes = 'inline-flex items-center justify-center whitespace-nowrap transition active:scale-[.98] disabled:pointer-events-none disabled:opacity-50 aria-busy:pointer-events-none '.$dimensions.' '.$classes;
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-fastleo::icon :name="$icon" @class(['shrink-0', 'size-4' => $size !== 'sm' || $variant === 'icon', 'size-[15px]' => $size === 'sm' && ! str_starts_with($variant, 'icon')]) />@endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>
        @if ($icon)<x-fastleo::icon :name="$icon" @class(['shrink-0', 'size-4' => $size !== 'sm' || $variant === 'icon', 'size-[15px]' => $size === 'sm' && ! str_starts_with($variant, 'icon')]) />@endif
        {{ $slot }}
    </button>
@endif
