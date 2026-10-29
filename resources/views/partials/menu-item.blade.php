{{-- Пункт бокового меню. $url, $icon, $label, $active, $badge (число или null).
     В свёрнутом меню остаётся иконка, подпись уходит в подсказку справа, счётчик становится точкой. --}}
<a
    href="{{ $url }}"
    @class([
        'group/item relative flex h-8 items-center gap-2.5 rounded-[7px] px-2.5 text-base transition-colors lg:collapsed:h-9 lg:collapsed:w-10 lg:collapsed:justify-center lg:collapsed:self-center lg:collapsed:px-0',
        'bg-primary-soft font-semibold text-primary-text' => $active,
        'text-ink-2 hover:bg-hover hover:text-ink' => ! $active,
    ])
    @if ($active) aria-current="page" @endif
>
    <x-fastleo::icon :name="$icon" class="size-4 shrink-0 lg:collapsed:size-[18px]" />
    <span class="min-w-0 flex-1 truncate lg:collapsed:sr-only">{{ $label }}</span>
    @if ($badge !== null)
        <span class="flex h-[18px] min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-white tabular-nums lg:collapsed:absolute lg:collapsed:top-[5px] lg:collapsed:right-1.5 lg:collapsed:h-2 lg:collapsed:min-w-0 lg:collapsed:p-0 lg:collapsed:text-[0px] lg:collapsed:ring-2 lg:collapsed:ring-sidebar" data-badge>{{ $badge }}</span>
    @endif
    <span class="pointer-events-none absolute top-1/2 left-[calc(100%+10px)] z-50 hidden -translate-y-1/2 rounded-[7px] bg-inverse px-2.5 py-1 text-xs font-[550] whitespace-nowrap text-on-inverse opacity-0 shadow-pop transition group-hover/item:opacity-100 group-focus-visible/item:opacity-100 lg:collapsed:block" aria-hidden="true">{{ $label }}</span>
</a>
