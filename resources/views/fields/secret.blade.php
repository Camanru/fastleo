{{-- Secret: всегда пустое; при редактировании пустое поле оставляет прежнее значение. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <input
        type="password"
        id="{{ $id }}"
        name="{{ $name }}"
        autocomplete="off"
        spellcheck="false"
        @if ($field->getPlaceholder() || ($record?->exists ?? false)) placeholder="{{ $field->getPlaceholder() ?? __('fastleo::resources.secret_keep') }}" @endif
        @required($field->isRequired() && ! ($record?->exists ?? false))
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input font-mono sm:max-w-xl', 'fastleo-input-invalid' => $errors->has($path)])
    >
</x-fastleo::field>
