@props(['title' => null, 'description' => null])
{{-- Карточка содержимого: <x-fastleo::card title="Заголовок" description="Пояснение">…</x-fastleo::card>.
     Слот action — действие в правом верхнем углу: <x-slot:action>…</x-slot:action>. Для виджетов и своих страниц админки. --}}
<section {{ $attributes->class(['fastleo-card flex min-w-0 flex-col gap-3 p-4 sm:p-5']) }}>
    @if ($title || isset($action))
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 flex-col gap-0.5">
                @if ($title)
                    <h2 class="text-md font-semibold tracking-[-0.01em]">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="text-sm text-ink-2">{{ $description }}</p>
                @endif
            </div>
            @isset($action)
                <div class="flex shrink-0 items-center gap-2">{{ $action }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</section>
