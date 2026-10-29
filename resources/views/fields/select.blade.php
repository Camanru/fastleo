{{-- Select: один вариант или несколько. Для множественного пустое скрытое поле отправляет «ничего не выбрано».
     С dependsOn() варианты подгружаются с сервера при смене поля-зависимости. --}}
@php
    $old = old($path, $value);
    $selected = array_map('strval', array_filter(is_array($old) ? $old : ($old === null || $old === '' ? [] : [$old]), 'is_scalar'));
    $depends = $field->getDependsOn();
    $dependency = $depends === null ? null : $resource->controllingValue($depends, $record, session()->hasOldInput() ? session()->getOldInput() : null);
    $options = $field->getOptions($dependency);
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($field->isMultiple() && ! $field->isDisabled() && ! $field->isReadonly())
        <input type="hidden" name="{{ $name }}" value="">
    @endif
    <select
        id="{{ $id }}"
        @if ($depends !== null && $field->isWritable()) x-data="fastleoDependentSelect({ url: @js(route('fastleo.resources.options', [$resource::slug(), $field->name])), depends: @js($depends) })" @endif
        name="{{ $name }}{{ $field->isMultiple() ? '[]' : '' }}"
        @if ($field->isMultiple()) multiple size="{{ min(8, max(3, count($options))) }}" @endif
        @required($field->isRequired())
        @disabled($field->isDisabled() || $field->isReadonly())
        @if ($errors->has($path) || $errors->has($path.'.*')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input', 'fastleo-input-invalid' => $errors->has($path) || $errors->has($path.'.*')])
    >
        @unless ($field->isMultiple())
            <option value="">{{ $field->getPlaceholder() ?? '—' }}</option>
        @endunless
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
        @endforeach
        @foreach ($field->savedValues($record) as $savedValue)
            @unless (array_key_exists($savedValue, $options))
                <option value="{{ $savedValue }}" @selected(in_array($savedValue, $selected, true))>{{ $savedValue }}</option>
            @endunless
        @endforeach
    </select>
</x-fastleo::field>
