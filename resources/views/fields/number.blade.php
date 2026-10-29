{{-- Number: целое или дробное число. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <input
        type="number"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ fastleo_old($path, $value) }}"
        step="{{ $field->getStep() }}"
        @if ($field->getMin() !== null) min="{{ $field->getMin() }}" @endif
        @if ($field->getMax() !== null) max="{{ $field->getMax() }}" @endif
        @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
        inputmode="{{ $field->isInteger() ? 'numeric' : 'decimal' }}"
        @required($field->isRequired())
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input', 'fastleo-input-invalid' => $errors->has($path)])
    >
</x-fastleo::field>
