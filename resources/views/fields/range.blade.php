{{-- Range: число ползунком, текущее значение справа в строке подписи. --}}
@php($current = fastleo_old($path, $value) ?? $field->getMin())
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div class="relative sm:max-w-md" x-data="{ value: @js((string) $current) }">
        <input
            type="range"
            id="{{ $id }}"
            name="{{ $name }}"
            x-model="value"
            value="{{ $current }}"
            min="{{ $field->getMin() }}"
            max="{{ $field->getMax() }}"
            step="{{ $field->getStep() }}"
            class="h-2 w-full cursor-pointer accent-primary"
            @disabled($field->isDisabled() || $field->isReadonly())
            @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        >
        <output for="{{ $id }}" class="absolute -top-[26px] right-0 text-sm font-medium tabular-nums text-ink" x-text="value">{{ $current }}</output>
    </div>
</x-fastleo::field>
