{{-- Фильтр по связи: список с поиском (fastleoCombobox); выбор сразу применяет фильтр. --}}
@php
    $remote = $filter->isRemote();
    $combobox = [
        'multiple' => false,
        'options' => $remote ? [] : $filter->searchOptions(),
        'selected' => $filter->selected($value),
        'url' => $remote ? route('fastleo.resources.filter-options', [$resource::slug(), $filter->name]) : null,
        'remote' => $remote,
        'depends' => null,
        'record' => null,
    ];
    $id = 'filter-'.$filter->name;
@endphp
<div class="flex min-w-0 flex-col gap-1 text-sm">
    <label for="{{ $id }}" class="text-ink-2">{{ $filter->getLabel() }}</label>
    <div
        x-data="fastleoCombobox(@js($combobox))"
        class="relative"
        @click.outside="close()"
        @keydown.escape.prevent.stop="close()"
        x-on:input="$event.target.type === 'hidden' && $event.target.form?.requestSubmit()"
    >
        <input type="hidden" name="filter[{{ $filter->name }}]" form="{{ $form }}" :value="selected[0]?.value ?? ''">
        <div class="fastleo-input flex min-h-9 cursor-text items-center gap-1.5 py-1.5 focus-within:border-primary focus-within:shadow-[0_0_0_3px_var(--color-ring)]" @click="openList()">
            <div class="relative min-w-0 flex-1">
                <span x-show="selected.length && search === ''" x-text="selected[0]?.label" class="pointer-events-none absolute inset-y-0 left-0.5 flex items-center truncate"></span>
                <input
                    type="text"
                    id="{{ $id }}"
                    x-ref="search"
                    x-model="search"
                    @input.stop="typed()"
                    @focus="openList()"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="choose(active)"
                    role="combobox"
                    autocomplete="off"
                    aria-autocomplete="list"
                    aria-controls="{{ $id }}-listbox"
                    :aria-expanded="open"
                    :placeholder="selected.length ? '' : @js(__('fastleo::resources.filter_all'))"
                    class="w-full border-0 bg-transparent p-0.5 text-base outline-none focus:ring-0 sm:text-sm"
                >
            </div>
            <button type="button" x-show="selected.length" x-cloak class="text-ink-3 hover:text-danger" @click.stop="clear()" aria-label="{{ __('fastleo::resources.combobox_clear') }}">
                <x-fastleo::icon name="x" class="size-4" />
            </button>
            <x-fastleo::icon name="chevrons-up-down" class="size-4 shrink-0 text-ink-3" />
        </div>
        <ul
            id="{{ $id }}-listbox"
            x-show="open"
            x-cloak
            role="listbox"
            class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-[10px] border border-line bg-surface p-1 text-base shadow-pop"
        >
            <template x-for="(option, index) in options" :key="option.value">
                <li
                    role="option"
                    :aria-selected="isSelected(option)"
                    @mousedown.prevent="choose(index)"
                    @mouseenter="active = index"
                    class="cursor-pointer truncate px-3 py-2"
                    :class="index === active ? 'bg-hover' : ''"
                    x-text="option.label"
                ></li>
            </template>
            <li x-show="loading" class="px-2 py-2 text-ink-2">{{ __('fastleo::resources.combobox_loading') }}</li>
            <li x-show="!loading && options.length === 0" class="px-2 py-2 text-ink-2">{{ __('fastleo::resources.combobox_empty') }}</li>
        </ul>
    </div>
</div>
