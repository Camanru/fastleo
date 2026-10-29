{{-- Секция: заголовок, пояснение, поля; может сворачиваться. --}}
<section
    @class(['flex flex-col gap-4', 'fastleo-card p-4 sm:p-5' => $card ?? false, 'rounded-[10px] border border-line p-4' => ! ($card ?? false)])
    @if ($layout->isCollapsible()) x-data="{ open: {{ $layout->isCollapsed() && ! $errors->any() ? 'false' : 'true' }} }" @endif
>
    <header class="flex items-start gap-2">
        <div class="min-w-0 flex-1">
            <h3 class="text-md font-semibold tracking-[-0.01em]">{{ $layout->label }}</h3>
            @if ($layout->getDescription())
                <p class="mt-0.5 text-sm text-ink-2">{{ $layout->getDescription() }}</p>
            @endif
        </div>
        @if ($layout->isCollapsible())
            <button type="button" class="rounded-md p-1 text-ink-2 hover:bg-hover" @click="open = ! open" :aria-expanded="open">
                <span class="sr-only" x-text="open ? @js(__('fastleo::resources.collapse')) : @js(__('fastleo::resources.expand'))"></span>
                <x-fastleo::icon name="chevron-down" class="size-5 transition-transform" ::class="open || '-rotate-90'" />
            </button>
        @endif
    </header>
    <div class="flex flex-col gap-5" @if ($layout->isCollapsible()) x-show="open" @if ($layout->isCollapsed() && ! $errors->any()) x-cloak @endif @endif>
        @include('fastleo::form.items', ['items' => $layout->children(), 'record' => $record, 'card' => false, 'cards' => false])
    </div>
</section>
