{{-- Text, Email, Url, Tel: однострочный ввод. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <input
        type="{{ $field->inputType() }}"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ fastleo_old($path, $value) }}"
        maxlength="{{ $field->getMaxLength() }}"
        @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
        @required($field->isRequired())
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input', 'fastleo-input-invalid' => $errors->has($path)])
    >
</x-fastleo::field>
