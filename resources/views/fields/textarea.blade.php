{{-- Textarea: многострочный текст. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $field->getRows() }}"
        @if ($field->getMaxLength()) maxlength="{{ $field->getMaxLength() }}" @endif
        @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
        @required($field->isRequired())
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input', 'fastleo-input-invalid' => $errors->has($path)])
    >{{ fastleo_old($path, $value) }}</textarea>
</x-fastleo::field>
