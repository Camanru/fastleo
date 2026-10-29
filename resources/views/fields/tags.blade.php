{{-- Tags: метки свободным вводом. Пустое скрытое поле отправляет «меток нет». --}}
@php
    $old = old($path, $value);
    $tags = array_values(array_filter(is_array($old) ? $old : [], 'is_string'));
    $writable = ! $field->isDisabled() && ! $field->isReadonly();
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div
        x-data="fastleoTags({ tags: @js($tags), max: {{ $field->getMaxTagLength() }} })"
        @class([
            'fastleo-input flex min-h-9 flex-wrap items-center gap-1.5 py-1.5 focus-within:border-primary focus-within:shadow-[0_0_0_3px_var(--color-ring)]',
            'fastleo-input-invalid' => $errors->has($path) || $errors->has($path.'.*'),
            'bg-sunken ' => ! $writable,
        ])
    >
        @if ($writable)
            <input type="hidden" name="{{ $name }}" value="">
        @endif
        <template x-for="(tag, index) in tags" :key="tag">
            <span class="inline-flex items-center gap-1 rounded-[6px] bg-primary-soft py-0.5 pr-1.5 pl-2 text-sm font-medium text-primary-text">
                @if ($writable)
                    <input type="hidden" name="{{ $name }}[]" :value="tag">
                @endif
                <span x-text="tag"></span>
                @if ($writable)
                    <button type="button" class="text-ink-2 hover:text-danger" @click="remove(index)" :aria-label="@js(__('fastleo::resources.tag_remove')) + ' ' + tag">
                        <x-fastleo::icon name="x" class="size-3.5" />
                    </button>
                @endif
            </span>
        </template>
        @if ($writable)
            <input
                type="text"
                id="{{ $id }}"
                x-model="draft"
                @keydown.enter.prevent="add()"
                @input="input()"
                @keydown.backspace="if (draft === '') removeLast()"
                @blur="add()"
                maxlength="{{ $field->getMaxTagLength() }}"
                @if ($field->getSuggestions()) list="{{ $id }}-suggestions" @endif
                placeholder="{{ $field->getPlaceholder() ?? __('fastleo::resources.tag_placeholder') }}"
                @if ($errors->has($path) || $errors->has($path.'.*')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
                class="min-w-32 flex-1 border-0 bg-transparent p-0.5 text-base outline-none focus:ring-0 sm:text-sm"
            >
            @if ($field->getSuggestions())
                <datalist id="{{ $id }}-suggestions">
                    @foreach ($field->getSuggestions() as $suggestion)
                        <option value="{{ $suggestion }}"></option>
                    @endforeach
                </datalist>
            @endif
        @endif
    </div>
</x-fastleo::field>
