{{-- Логотип и название админки. Без своего логотипа — знак из трёх скошенных полос в цвете акцента.
     $subtitle (необязательно) — вторая строка под названием, например адрес сайта. --}}
<span class="flex min-w-0 items-center gap-2.5">
    @if ($logo = \Fastleo\Support\Branding::logo())
        <img src="{{ $logo }}" alt="" class="size-7 shrink-0 rounded-[8px] object-contain">
    @else
        <span class="flex size-7 shrink-0 flex-col justify-center gap-[2.5px] rounded-[8px] bg-primary px-1.5 shadow-[inset_0_1px_0_rgb(255_255_255/.2)]" aria-hidden="true">
            <span class="h-[2.5px] w-full -skew-x-[20deg] rounded-sm bg-white"></span>
            <span class="h-[2.5px] w-[72%] -skew-x-[20deg] rounded-sm bg-white/85"></span>
            <span class="h-[2.5px] w-[44%] -skew-x-[20deg] rounded-sm bg-white/70"></span>
        </span>
    @endif
    <span class="flex min-w-0 flex-col" data-brand-text>
        <span class="truncate text-base font-semibold tracking-[-0.01em]">{{ \Fastleo\Support\Branding::name() }}</span>
        @isset($subtitle)
            <span class="truncate text-xs text-ink-2">{{ $subtitle }}</span>
        @endisset
    </span>
</span>
