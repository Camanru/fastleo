{{-- Toggle: переключатель вкл/выкл. Скрытое поле «0» отправляет выключенное состояние. --}}
@php($checked = (bool) old($path, $value))
<x-fastleo::field :field="$field" :id="$id" :path="$path" :inline="true">
    @unless ($field->isDisabled() || $field->isReadonly())
        <input type="hidden" name="{{ $name }}" value="0">
    @endunless
    <label for="{{ $id }}" class="flex cursor-pointer items-center gap-3 text-sm font-medium text-ink">
        <input
            type="checkbox"
            role="switch"
            id="{{ $id }}"
            name="{{ $name }}"
            value="1"
            class="peer sr-only"
            @checked($checked)
            @disabled($field->isDisabled() || $field->isReadonly())
        >
        <span class="relative h-6 w-10 shrink-0 rounded-full bg-line-strong transition-colors duration-150 peer-checked:bg-primary peer-focus-visible:shadow-[0_0_0_3px_var(--color-ring)] peer-disabled:opacity-50 after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow-[0_1px_3px_rgb(0_0_0/.25)] after:transition-transform after:duration-200 after:ease-out-quint peer-checked:after:translate-x-4"></span>
        {{ $field->getLabel() }}
    </label>
</x-fastleo::field>
