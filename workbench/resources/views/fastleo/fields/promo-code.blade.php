{{-- Поле «Промокод» витрины: $field, $value, $name, $path, $id. Буквы показываются заглавными ещё до сохранения. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <input
        type="text"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ old($path, $value) }}"
        maxlength="{{ $field->getMaxLength() }}"
        autocomplete="off"
        spellcheck="false"
        style="text-transform: uppercase; font-family: ui-monospace, monospace"
        data-promo-code
        @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
        @required($field->isRequired())
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input', 'fastleo-input-invalid' => $errors->has($path)])
    >
</x-fastleo::field>
