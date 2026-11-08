{{-- Содержимое страницы ошибки: значок, код, заголовок, пояснение и кнопки. --}}
<div class="mx-auto flex max-w-[440px] flex-col items-center gap-4 py-10 text-center" role="alert">
    <span class="flex size-12 items-center justify-center rounded-[12px] bg-sunken text-ink-2">
        <x-fastleo::icon :name="$icon" class="size-6" />
    </span>
    <p class="text-sm font-medium text-ink-3">{{ $status }}</p>
    @if ($showTitle)
        <h1 class="text-xl font-[650] tracking-[-0.02em]">{{ $title }}</h1>
    @endif
    <p class="text-sm text-ink-2">{{ $text }} @if ($retry) {{ $retry }} @endif</p>
    <div class="flex flex-wrap items-center justify-center gap-2" x-data>
        @if ($status === 419)
            <x-fastleo::button variant="primary" icon="rotate-cw" x-on:click="location.reload()">{{ __('fastleo::errors.reload') }}</x-fastleo::button>
        @else
            <x-fastleo::button variant="primary" :href="$home" icon="layout-dashboard">{{ $signedIn ? __('fastleo::errors.home') : __('fastleo::errors.enter') }}</x-fastleo::button>
        @endif
        <x-fastleo::button x-on:click="history.length > 1 ? history.back() : (location.href = {{ \Illuminate\Support\Js::from($home) }})" icon="arrow-left">{{ __('fastleo::errors.back') }}</x-fastleo::button>
    </div>
</div>
