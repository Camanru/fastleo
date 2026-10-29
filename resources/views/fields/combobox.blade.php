{{-- Список с поиском для связей (BelongsTo — одна запись, BelongsToMany — несколько).
     Варианты — в странице или, если их много, поиском по серверу. Значения — в скрытых полях.
     С dependsOn() при смене поля-зависимости выбор сбрасывается, варианты запрашиваются заново. --}}
@php
    $old = old($path, $value);
    $keys = array_values(array_map('strval', array_filter(is_array($old) ? $old : [$old], fn ($key) => is_scalar($key) && $key !== '')));
    $writable = $field->isWritable();
    $depends = $field->getDependsOn();
    $dependency = $depends === null ? null : $resource->controllingValue($depends, $record, session()->hasOldInput() ? session()->getOldInput() : null);
    $remote = $writable && $field->isRemote($dependency);
    $combobox = [
        'multiple' => $field->isMultiple(),
        'options' => $writable && ! $remote ? $field->preloadedOptions($dependency) : [],
        'selected' => $field->optionsFor($keys, $dependency, $field->savedKeys($record)),
        'url' => $remote || ($writable && $depends !== null) ? route('fastleo.resources.options', [$resource::slug(), $field->name]) : null,
        'remote' => $remote,
        'depends' => $depends,
        'record' => $depends !== null && $record?->exists ? (string) $record->getKey() : null,
    ];
    $invalid = $errors->has($path) || $errors->has($path.'.*');
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if (! $writable)
        <div class="fastleo-input bg-sunken text-ink-2" id="{{ $id }}">
            {{ collect($combobox['selected'])->pluck('label')->join(', ') ?: '—' }}
        </div>
    @else
        <div
            x-data="fastleoCombobox(@js($combobox))"
            class="relative"
            @click.outside="close()"
            @keydown.escape.prevent.stop="close()"
            data-combobox="{{ $path }}"
        >
            @if ($field->isMultiple())
                <input type="hidden" name="{{ $name }}" value="">
                <template x-for="item in selected" :key="item.value">
                    <input type="hidden" name="{{ $name }}[]" :value="item.value">
                </template>
            @else
                <input type="hidden" name="{{ $name }}" :value="selected[0]?.value ?? ''">
            @endif

            <div
                @class(['fastleo-input flex min-h-9 cursor-text flex-wrap items-center gap-1.5 py-1.5 focus-within:border-primary focus-within:shadow-[0_0_0_3px_var(--color-ring)]', 'fastleo-input-invalid' => $invalid])
                @click="openList()"
            >
                @if ($field->isMultiple())
                    <template x-for="item in selected" :key="item.value">
                        <span class="inline-flex max-w-full items-center gap-1 rounded-[6px] bg-primary-soft py-0.5 pr-1.5 pl-2 text-sm font-medium text-primary-text">
                            <span class="truncate" x-text="item.label"></span>
                            <button type="button" class="text-ink-2 hover:text-danger" @click.stop="remove(item.value)" :aria-label="@js(__('fastleo::resources.combobox_remove')) + ' ' + item.label">
                                <x-fastleo::icon name="x" class="size-3.5" />
                            </button>
                        </span>
                    </template>
                @endif
                <div class="relative min-w-24 flex-1">
                    @unless ($field->isMultiple())
                        <span x-show="selected.length && search === ''" x-text="selected[0]?.label" class="pointer-events-none absolute inset-y-0 left-0.5 flex items-center truncate"></span>
                    @endunless
                    <input
                        type="text"
                        id="{{ $id }}"
                        x-ref="search"
                        x-model="search"
                        @input="typed()"
                        @focus="openList()"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="choose(active)"
                        @keydown.backspace="if (search === '' && multiple) removeLast()"
                        role="combobox"
                        autocomplete="off"
                        aria-autocomplete="list"
                        aria-controls="{{ $id }}-listbox"
                        :aria-expanded="open"
                        :aria-activedescendant="open && options[active] ? '{{ $id }}-option-' + active : null"
                        :placeholder="selected.length ? '' : @js($field->getPlaceholder() ?? __('fastleo::resources.combobox_placeholder'))"
                        @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
                        class="w-full border-0 bg-transparent p-0.5 text-base outline-none focus:ring-0 sm:text-sm"
                    >
                </div>
                @unless ($field->isMultiple() || $field->isRequired())
                    <button type="button" x-show="selected.length" x-cloak class="text-ink-3 hover:text-danger" @click.stop="clear()" aria-label="{{ __('fastleo::resources.combobox_clear') }}">
                        <x-fastleo::icon name="x" class="size-4" />
                    </button>
                @endunless
                <x-fastleo::icon name="chevrons-up-down" class="size-4 shrink-0 text-ink-3" />
            </div>

            <ul
                id="{{ $id }}-listbox"
                x-show="open"
                x-cloak
                role="listbox"
                @if ($field->isMultiple()) aria-multiselectable="true" @endif
                class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-[10px] border border-line bg-surface p-1 text-base shadow-pop"
            >
                <template x-for="(option, index) in options" :key="option.value">
                    <li
                        role="option"
                        :id="'{{ $id }}-option-' + index"
                        :aria-selected="isSelected(option)"
                        @mousedown.prevent="choose(index)"
                        @mouseenter="active = index"
                        class="flex h-8 cursor-pointer items-center gap-2 rounded-[6px] px-2"
                        :class="index === active ? 'bg-hover' : ''"
                    >
                        <span class="flex-1 truncate" x-text="option.label"></span>
                        <span x-show="isSelected(option)" class="text-primary-text"><x-fastleo::icon name="check" class="size-4" /></span>
                    </li>
                </template>
                <li x-show="loading" class="px-2 py-2 text-ink-2">{{ __('fastleo::resources.combobox_loading') }}</li>
                <li x-show="!loading && options.length === 0" class="px-2 py-2 text-ink-2">{{ __('fastleo::resources.combobox_empty') }}</li>
            </ul>
        </div>
    @endif
</x-fastleo::field>
