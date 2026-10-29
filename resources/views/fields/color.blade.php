{{-- Color: код HEX текстом и палитра браузера, связанные друг с другом. --}}
@php($current = (string) fastleo_old($path, $value))
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div class="flex items-center gap-2 sm:max-w-xs" x-data="{ color: @js($current) }">
        <input
            type="color"
            aria-label="{{ $field->getLabel() }}"
            class="h-10 w-12 shrink-0 cursor-pointer rounded-md border border-line-strong bg-surface p-1"
            :value="/^#[0-9a-f]{6}$/i.test(color) ? color : '#000000'"
            @input="color = $event.target.value; $el.closest('form')?.dispatchEvent(new Event('input'))"
            @disabled($field->isDisabled() || $field->isReadonly())
        >
        <input
            type="text"
            id="{{ $id }}"
            name="{{ $name }}"
            x-model="color"
            value="{{ $current }}"
            maxlength="9"
            placeholder="{{ $field->getPlaceholder() ?? '#4f46e5' }}"
            @required($field->isRequired())
            @readonly($field->isReadonly())
            @disabled($field->isDisabled())
            @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            @class(['fastleo-input font-mono', 'fastleo-input-invalid' => $errors->has($path)])
        >
    </div>
</x-fastleo::field>
