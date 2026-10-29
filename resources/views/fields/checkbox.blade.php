{{-- Checkbox: галочка. Скрытое поле «0» отправляет снятую галочку. --}}
@php($checked = (bool) old($path, $value))
<x-fastleo::field :field="$field" :id="$id" :path="$path" :inline="true">
    @unless ($field->isDisabled() || $field->isReadonly())
        <input type="hidden" name="{{ $name }}" value="0">
    @endunless
    <label for="{{ $id }}" class="flex items-center gap-2 text-sm font-medium text-ink">
        <input
            type="checkbox"
            id="{{ $id }}"
            name="{{ $name }}"
            value="1"
            class="size-4 rounded accent-primary"
            @checked($checked)
            @disabled($field->isDisabled() || $field->isReadonly())
        >
        {{ $field->getLabel() }}
    </label>
</x-fastleo::field>
