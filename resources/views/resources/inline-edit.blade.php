{{-- Правка значения в ячейке списка (inlineEditable()): поле ввода или список, сохранение при уходе из поля.
     Нужны $resource, $record, $field и $inline — результат InlineEdit::prepare(): kind (text, number, select), value, options, tones.
     Список с цветными метками красится в цвет метки выбранного варианта. --}}
@php
    $toneClasses = [
        'success' => '!border-transparent !bg-success-soft !text-success font-[550]',
        'warning' => '!border-transparent !bg-warning-soft !text-warning font-[550]',
        'danger' => '!border-transparent !bg-danger-soft !text-danger font-[550]',
        'info' => '!border-transparent !bg-info-soft !text-info font-[550]',
        'neutral' => '!border-transparent !bg-sunken !text-ink-2 font-[550]',
    ];
    $tones = array_map(fn (string $tone): string => $toneClasses[$tone] ?? $toneClasses['neutral'], $inline['tones']);
    $width = match ($inline['kind']) {
        'number' => 'min-w-24',
        'select' => 'min-w-32',
        default => 'min-w-40',
    };
@endphp
<div
    x-data="fastleoInline({ url: @js(route('fastleo.resources.inline', [$resource::slug(), $record->getKey(), $field->name])), value: @js($inline['value']), failed: @js(__('fastleo::resources.inline_failed')), tones: @js($tones) })"
    class="{{ $width }}"
    data-inline="{{ $field->name }}"
>
    @if ($inline['kind'] === 'select')
        <select
            x-model="value"
            x-on:change="save()"
            x-on:keydown.escape="revert()"
            :disabled="busy"
            :aria-invalid="error !== ''"
            :class="tones[value] ?? ''"
            aria-label="{{ $field->getLabel() }}"
            class="fastleo-input h-8 py-0 text-sm"
        >
            @foreach ($inline['options'] as $option)
                <option value="{{ $option['value'] }}" @selected($option['value'] === $inline['value'])>{{ $option['label'] }}</option>
            @endforeach
        </select>
    @else
        <input
            type="{{ $inline['kind'] === 'number' ? 'number' : 'text' }}"
            @if ($inline['kind'] === 'number') step="any" inputmode="decimal" @endif
            value="{{ $inline['value'] }}"
            x-model="value"
            x-on:change="save()"
            x-on:keydown.escape="revert()"
            :disabled="busy"
            :aria-invalid="error !== ''"
            aria-label="{{ $field->getLabel() }}"
            @class(['fastleo-input h-8 py-0 text-sm', 'text-right tabular-nums' => $inline['kind'] === 'number'])
        >
    @endif
    <span x-show="error" x-text="error" role="alert" class="mt-0.5 block text-xs text-danger" style="display: none"></span>
</div>
