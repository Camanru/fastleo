{{-- Меню действий одной записи (строка списка). $rowActions — действия, разрешённые для $record; $icon и $iconButton задаёт row-actions. --}}
<div x-data="fastleoMenu" @keydown.escape="menu = false" @click.outside="menu = false" @scroll.window="place()" @resize.window="menu = false">
    {{-- Как <x-fastleo::button variant="icon" icon="ellipsis">, но без компонентов: меню рисуется на каждую запись списка. --}}
    <button type="button" x-ref="button" x-on:click="toggle()" x-bind:aria-expanded="menu" aria-haspopup="menu" title="{{ __('fastleo::actions.menu') }}" class="{{ $iconButton }}">
        {{ $icon('ellipsis') }}
        <span class="sr-only">{{ __('fastleo::actions.menu') }}</span>
    </button>
    <div x-show="menu" x-cloak role="menu" :style="style" class="fixed z-40 w-60 rounded-[10px] border border-line bg-surface p-1 text-base shadow-pop">
        @foreach ($rowActions as $key => $action)
            <button
                type="button"
                role="menuitem"
                @class(['flex h-8 w-full items-center gap-2.5 rounded-[6px] px-2 text-left hover:bg-hover', 'text-danger' => $action->isDanger()])
                x-on:click="menu = false; run(@js($key), [@js((string) $record->getKey())], @js($action->clientOptions()))"
            >
                {{ $icon($action->getIcon(), 'size-[15px] shrink-0 '.($action->isDanger() ? '' : 'text-ink-2')) }}
                {{ $action->getLabel() }}
            </button>
        @endforeach
    </div>
</div>
